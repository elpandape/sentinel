<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Dispatch\Settlement;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\verifier;

it('settles the whole batch in one chain', function (): void {
    $written = app(Settlement::class)->settleBatch([auditData(), auditData(), auditData()]);

    expect($written)->toHaveCount(3)
        ->and($written->entries->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('reads the tail of the stream once for the whole batch', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(Settlement::class)->settleBatch([auditData(), auditData(), auditData()]);

    $tails = array_filter(DB::getRawQueryLog(), static function (array $query): bool {
        $sql = strtolower((string) $query['raw_query']);

        return str_contains($sql, 'sequence') && str_contains($sql, 'desc');
    });

    expect($tails)->toHaveCount(1);
});

it('announces the cycle once per entry, not once per batch', function (): void {
    $creating = 0;
    $created = 0;

    app(Dispatcher::class)->listen(AuditCreating::class, static function () use (&$creating): void {
        $creating++;
    });
    app(Dispatcher::class)->listen(AuditCreated::class, static function () use (&$created): void {
        $created++;
    });

    app(Settlement::class)->settleBatch([auditData(), auditData(), auditData()]);

    expect($creating)->toBe(3)->and($created)->toBe(3);
});

it('leaves out an entry that already has one, and settles the rest', function (): void {
    $landed = frozenUlid('LANDED01');

    ledger()->write(auditData(['capture_id' => $landed]));

    $written = app(Settlement::class)->settleBatch([
        auditData(['capture_id' => $landed]),
        auditData(['capture_id' => frozenUlid('FRESH001')]),
    ]);

    expect($written)->toHaveCount(1)
        ->and($written->entries->firstOrFail()->capture_id)->toBe(frozenUlid('FRESH001'))
        ->and(Audit::query()->count())->toBe(2);
});

it('settles a capture the same batch names twice exactly once', function (): void {
    $twice = frozenUlid('TWICE001');

    $written = app(Settlement::class)->settleBatch([
        auditData(['capture_id' => $twice]),
        auditData(['capture_id' => frozenUlid('OTHER001')]),
        auditData(['capture_id' => $twice]),
    ]);

    expect($written)->toHaveCount(2)
        ->and(Audit::query()->count())->toBe(2)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('announces the repeat neither as about to be written nor as written', function (): void {
    $twice = frozenUlid('TWICE002');
    $announced = 0;

    app(Dispatcher::class)->listen(AuditCreating::class, static function () use (&$announced): void {
        $announced++;
    });

    app(Settlement::class)->settleBatch([
        auditData(['capture_id' => $twice]),
        auditData(['capture_id' => $twice]),
    ]);

    expect($announced)->toBe(1);
});

it('keeps a repeated capture from taking down the entries around it', function (): void {
    $twice = frozenUlid('TWICE003');

    app(Settlement::class)->settleBatch([
        auditData(['capture_id' => frozenUlid('BEFORE01')]),
        auditData(['capture_id' => $twice]),
        auditData(['capture_id' => $twice]),
        auditData(['capture_id' => frozenUlid('AFTER001')]),
    ]);

    expect(Audit::query()->orderBy('sequence')->pluck('capture_id')->all())
        ->toBe([frozenUlid('BEFORE01'), $twice, frozenUlid('AFTER001')]);
});

it('settles an entry that named no capture alongside ones that did', function (): void {
    $written = app(Settlement::class)->settleBatch([
        auditData(['capture_id' => frozenUlid('NAMED001')]),
        auditData(),
        auditData(['capture_id' => frozenUlid('NAMED001')]),
    ]);

    expect($written)->toHaveCount(2)
        ->and($written->entries->pluck('capture_id')->all())->toBe([frozenUlid('NAMED001'), null]);
});

it('says nothing at all for a batch that had already landed whole', function (): void {
    $landed = frozenUlid('LANDED02');

    ledger()->write(auditData(['capture_id' => $landed]));

    $announced = 0;

    app(Dispatcher::class)->listen(AuditCreating::class, static function () use (&$announced): void {
        $announced++;
    });

    $written = app(Settlement::class)->settleBatch([auditData(['capture_id' => $landed])]);

    expect($written)->toBeEmpty()
        ->and($announced)->toBe(0)
        ->and(Audit::query()->count())->toBe(1);
});

it('settles an empty batch as nothing, without touching the ledger', function (): void {
    expect(app(Settlement::class)->settleBatch([]))->toBeEmpty()
        ->and(Audit::query()->count())->toBe(0);
});

it('settles entries that carry no identifier every time it is handed them', function (): void {
    $audit = auditData();
    $audit->capture_id = null;

    app(Settlement::class)->settleBatch([$audit]);
    app(Settlement::class)->settleBatch([$audit]);

    expect(Audit::query()->count())->toBe(2);
});

it('says how many it was handed, not only how many it wrote', function (): void {
    $landed = frozenUlid('HANDED01');

    ledger()->write(auditData(['capture_id' => $landed]));

    $written = app(Settlement::class)->settleBatch([
        auditData(['capture_id' => $landed]),
        auditData(['capture_id' => frozenUlid('HANDED02')]),
    ]);

    expect($written->taken)->toBe(2)
        ->and($written)->toHaveCount(1)
        ->and($written->skipped())->toBe(1);
});

it('counts a batch it did not write at all as skipped whole', function (): void {
    $landed = frozenUlid('HANDED03');

    ledger()->write(auditData(['capture_id' => $landed]));

    $written = app(Settlement::class)->settleBatch([auditData(['capture_id' => $landed])]);

    expect($written->taken)->toBe(1)
        ->and($written)->toBeEmpty()
        ->and($written->skipped())->toBe(1);
});

it('hands its entries to whoever iterates it, not only to whoever counts', function (): void {
    $written = app(Settlement::class)->settleBatch([auditData(), auditData()]);

    $walked = [];

    foreach ($written as $entry) {
        $walked[] = $entry->sequence;
    }

    expect($walked)->toBe([1, 2]);
});
