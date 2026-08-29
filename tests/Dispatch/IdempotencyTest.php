<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Database\UniqueConstraintViolationException;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\fanout;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\verifier;

it('leaves one entry when the queue hands the same capture back', function (): void {
    config()->set('sentinel.mode', 'queue');
    config()->set('queue.default', 'sync');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    $settled = Audit::query()->sole();

    app()->call([new SettleAudit(auditData([
        'capture_id' => $settled->capture_id,
        'subject_type' => $settled->subject_type,
        'subject_id' => $settled->subject_id,
    ])->toPayload()), 'handle']);

    expect(Audit::query()->count())->toBe(1);
});

it('settles a capture at most once, however many times the job is handed it', function (): void {
    $payload = auditData(['capture_id' => frozenUlid('RETRY1')])->toPayload();

    app()->call([new SettleAudit($payload), 'handle']);
    app()->call([new SettleAudit($payload), 'handle']);

    expect(Audit::query()->count())->toBe(1);
});

it('leaves a chain with no gap in it after the second run wrote nothing', function (): void {
    $payload = auditData(['capture_id' => frozenUlid('RETRY2')])->toPayload();

    app()->call([new SettleAudit($payload), 'handle']);
    app()->call([new SettleAudit($payload), 'handle']);
    app()->call([new SettleAudit(auditData(['capture_id' => frozenUlid('RETRY3')])->toPayload()), 'handle']);

    expect(verifier()->verifyStream('global')->isIntact())->toBeTrue()
        ->and(Audit::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
});

it('settles a capture that carries no identifier every time it is asked to', function (): void {
    $audit = auditData();
    $audit->capture_id = null;

    app()->call([new SettleAudit($audit->toPayload()), 'handle']);
    app()->call([new SettleAudit($audit->toPayload()), 'handle']);

    expect(Audit::query()->count())->toBe(2);
});

it('says which of a set of captures already have an entry', function (): void {
    ledger()->write(auditData(['capture_id' => frozenUlid('SETTLED1')]));

    expect(ledger()->settled([frozenUlid('SETTLED1'), frozenUlid('ABSENT01')]))
        ->toBe([frozenUlid('SETTLED1')]);
});

it('answers the same question over plain arrays', function (): void {
    $memory = app(MemoryLedger::class);
    $memory->write(auditData(['capture_id' => frozenUlid('SETTLED2')]));
    $memory->write(auditData());

    expect($memory->settled([frozenUlid('SETTLED2'), frozenUlid('ABSENT02')]))
        ->toBe([frozenUlid('SETTLED2')]);
});

it('hands back the violation instead of resealing a chain nobody can write', function (): void {
    $identifier = frozenUlid('RACED001');

    ledger()->write(auditData(['capture_id' => $identifier]));

    expect(static fn (): Audit => app(DatabaseLedger::class)->write(auditData(['capture_id' => $identifier])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('leaves the chain exactly as long as it was after refusing the duplicate', function (): void {
    $identifier = frozenUlid('RACED002');

    ledger()->write(auditData(['capture_id' => $identifier]));

    rescue(static fn (): Audit => app(DatabaseLedger::class)->write(auditData(['capture_id' => $identifier])), report: false);

    expect(Audit::query()->count())->toBe(1)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('settles the rest of a batch whose first entry had already landed', function (): void {
    $landed = frozenUlid('BATCH001');

    ledger()->write(auditData(['capture_id' => $landed]));

    $settled = app(DatabaseLedger::class)->writeMany([
        auditData(['capture_id' => $landed]),
        auditData(['capture_id' => frozenUlid('BATCH002')]),
    ]);

    expect($settled)->toHaveCount(1)
        ->and($settled->firstOrFail()->capture_id)->toBe(frozenUlid('BATCH002'))
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('asks the destination whose chain the sequence belongs to', function (): void {
    $ledger = fanout(app(DatabaseLedger::class), [app(MemoryLedger::class)]);

    $ledger->write(auditData(['capture_id' => frozenUlid('FANOUT01')]));

    expect($ledger->settled([frozenUlid('FANOUT01')]))->toBe([frozenUlid('FANOUT01')]);
});

it('answers for nothing when the destination it reads from cannot look a capture up', function (): void {
    $ledger = fanout(app(NullLedger::class), [app(MemoryLedger::class)]);

    $ledger->write(auditData(['capture_id' => frozenUlid('FANOUT02')]));

    expect($ledger->settled([frozenUlid('FANOUT02')]))->toBeEmpty();
});
