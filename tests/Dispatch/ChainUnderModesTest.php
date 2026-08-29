<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 2);
    config()->set('queue.default', 'sync');
});

it('leaves a chain that verifies after a full cycle, whichever mode wrote it', function (string $mode): void {
    config()->set('sentinel.mode', $mode);

    $subject = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $subject->update(['name' => 'Grace']);
    Sentinel::event('invoice.approved')->subject($subject)->record();

    app()->terminate();

    expect(Audit::query()->count())->toBe(3)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
})->with(['sync', 'queue', 'buffered']);

it('still reproduces the frozen hashes after a cycle in that mode', function (string $mode): void {
    config()->set('sentinel.mode', $mode);

    AuditedSubject::query()->create(['name' => 'Ada'])->update(['name' => 'Grace']);

    app()->terminate();

    foreach (GoldenLedger::entries() as [$attributes, $canonical, $hash]) {
        $frozen = new Audit()->forceFill($attributes);

        expect(new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($frozen)))->toBe($canonical)
            ->and(hasher()->hash($frozen))->toBe($hash);
    }
})->with(['sync', 'queue', 'buffered']);

it('gives a batch the same chain as the same entries written one at a time', function (): void {
    ledger()->writeMany([
        auditData(['stream' => 'batched']),
        auditData(['stream' => 'batched']),
        auditData(['stream' => 'batched']),
    ]);

    ledger()->write(auditData(['stream' => 'oneByOne']));
    ledger()->write(auditData(['stream' => 'oneByOne']));
    ledger()->write(auditData(['stream' => 'oneByOne']));

    $batched = Audit::query()->where('stream', 'batched')->orderBy('sequence')->get();
    $singly = Audit::query()->where('stream', 'oneByOne')->orderBy('sequence')->get();

    expect($batched->pluck('sequence')->all())->toBe($singly->pluck('sequence')->all())
        ->and($batched->pluck('previous_hash')->all())->toBe([null, $batched[0]->hash, $batched[1]->hash])
        ->and($singly->pluck('previous_hash')->all())->toBe([null, $singly[0]->hash, $singly[1]->hash])
        ->and(verifier()->verifyStream('batched')->isIntact())->toBeTrue()
        ->and(verifier()->verifyStream('oneByOne')->isIntact())->toBeTrue();
});

it('reads one tail for a batch and one per entry written singly', function (): void {
    $tails = static function (callable $write): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $write();

        $log = DB::getRawQueryLog();

        DB::disableQueryLog();

        return count(array_filter($log, static function (array $query): bool {
            $sql = strtolower((string) $query['raw_query']);

            return str_contains($sql, 'sequence') && str_contains($sql, 'desc');
        }));
    };

    $batched = $tails(static fn (): mixed => ledger()->writeMany([
        auditData(['stream' => 'batched']),
        auditData(['stream' => 'batched']),
        auditData(['stream' => 'batched']),
    ]));

    $singly = $tails(static function (): void {
        ledger()->write(auditData(['stream' => 'oneByOne']));
        ledger()->write(auditData(['stream' => 'oneByOne']));
        ledger()->write(auditData(['stream' => 'oneByOne']));
    });

    expect($batched)->toBe(1)->and($singly)->toBe(3);
});

it('numbers the settlement by arrival and keeps the fact in its own order', function (): void {
    $earlier = auditData(['occurred_at' => new DateTimeImmutable('2026-08-29 10:00:00.000001')]);
    $later = auditData(['occurred_at' => new DateTimeImmutable('2026-08-29 10:00:00.000002')]);

    ledger()->write($later);
    ledger()->write($earlier);

    $bySequence = Audit::query()->orderBy('sequence')->pluck('occurred_at')->all();
    $byOccurrence = Sentinel::timeline()->get()->pluck('occurred_at')->all();

    expect($bySequence[0]?->format('u'))->toBe('000002')
        ->and($bySequence[1]?->format('u'))->toBe('000001')
        ->and($byOccurrence[0]?->format('u'))->toBe('000001')
        ->and($byOccurrence[1]?->format('u'))->toBe('000002');
});

it('announces the cycle once per entry, wherever each part of it happens', function (string $mode, int $created): void {
    config()->set('sentinel.mode', $mode);
    config()->set('sentinel.buffer.size', 1);

    $counts = ['auditing' => 0, 'creating' => 0, 'created' => 0, 'audited' => 0];
    $events = app(Dispatcher::class);

    $events->listen(Auditing::class, static function () use (&$counts): void {
        $counts['auditing']++;
    });
    $events->listen(AuditCreating::class, static function () use (&$counts): void {
        $counts['creating']++;
    });
    $events->listen(AuditCreated::class, static function () use (&$counts): void {
        $counts['created']++;
    });
    $events->listen(Audited::class, static function () use (&$counts): void {
        $counts['audited']++;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($counts)->toBe(['auditing' => 1, 'creating' => $created, 'created' => $created, 'audited' => 1]);
})->with([
    'sync' => ['sync', 1],
    'queue' => ['queue', 1],
    'buffered' => ['buffered', 1],
]);

it('closes the journey in the process that captured, and the ledger pair where the entry lands', function (): void {
    config()->set('sentinel.mode', 'queue');

    Bus::fake();

    $counts = ['auditing' => 0, 'creating' => 0, 'audited' => 0];
    $events = app(Dispatcher::class);

    $events->listen(Auditing::class, static function () use (&$counts): void {
        $counts['auditing']++;
    });
    $events->listen(AuditCreating::class, static function () use (&$counts): void {
        $counts['creating']++;
    });
    $events->listen(Audited::class, static function () use (&$counts): void {
        $counts['audited']++;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($counts)->toBe(['auditing' => 1, 'creating' => 0, 'audited' => 1]);
});

it('gives a flushed batch the same chain as the same entries settled one at a time', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.size', 500);

    foreach (['Ada', 'Grace', 'Barbara'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    app()->terminate();

    $flushed = Audit::query()->orderBy('sequence')->get();

    config()->set('sentinel.mode', 'sync');
    config()->set('sentinel.integrity.stream', static fn (): string => 'oneByOne');

    foreach (['Ada', 'Grace', 'Barbara'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    $singly = Audit::query()->where('stream', 'oneByOne')->orderBy('sequence')->get();

    expect($flushed->pluck('sequence')->all())->toBe($singly->pluck('sequence')->all())
        ->and($flushed->pluck('previous_hash')->all())->toBe([null, $flushed[0]->hash, $flushed[1]->hash])
        ->and($singly->pluck('previous_hash')->all())->toBe([null, $singly[0]->hash, $singly[1]->hash])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue()
        ->and(verifier()->verifyStream('oneByOne')->isIntact())->toBeTrue();
});

it('settles a flush in one tail read, however many entries were waiting', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.size', 500);

    foreach (['Ada', 'Grace', 'Barbara'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    app()->terminate();

    $tails = array_filter(DB::getRawQueryLog(), static function (array $query): bool {
        $sql = strtolower((string) $query['raw_query']);

        return str_contains($sql, 'sequence') && str_contains($sql, 'desc');
    });

    expect($tails)->toHaveCount(1)
        ->and(Audit::query()->count())->toBe(3);
});
