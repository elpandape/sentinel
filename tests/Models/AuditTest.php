<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Tests\Fixtures\CustomAudit;
use ElPandaPe\Sentinel\Tests\Fixtures\IntKeySubject;
use ElPandaPe\Sentinel\Tests\Fixtures\UlidKeySubject;
use ElPandaPe\Sentinel\Tests\Fixtures\UuidKeySubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\withSortedKeys;

it('reads its table name from the configuration', function (): void {
    config()->set('sentinel.tables.prefix', 'acme_');

    expect(new Audit()->getTable())->toBe('acme_audits');
});

it('reads its connection from the configuration', function (): void {
    config()->set('sentinel.database.connection', 'audits');

    expect(new Audit()->getConnectionName())->toBe('audits');
});

it('carries a ulid key that it does not increment', function (): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    expect($audit->getIncrementing())->toBeFalse()
        ->and($audit->getKeyType())->toBe('string')
        ->and($audit->id)->toHaveLength(26);
});

it('never writes an updated_at', function (): void {
    expect(Audit::UPDATED_AT)->toBeNull();
});

it('casts severity and source to their enums', function (): void {
    insertAudit(['severity' => 'warning', 'source' => 'cli']);

    $audit = Audit::query()->firstOrFail();

    expect($audit->severity)->toBe(Severity::Warning)
        ->and($audit->source)->toBe(Source::Cli);
});

it('round trips every json column without loss, whatever order the engine keeps the keys in', function (array $value): void {
    Audit::query()->create(collect(auditRow())->except('id')->merge([
        'context' => $value,
        'before' => $value,
        'after' => $value,
        'changes' => $value,
        'metadata' => $value,
        'encryption' => $value,
        'criteria' => $value,
    ])->all());

    $fresh = Audit::query()->firstOrFail();

    $expected = withSortedKeys($value);

    expect(withSortedKeys($fresh->context))->toBe($expected)
        ->and(withSortedKeys($fresh->before ?? []))->toBe($expected)
        ->and(withSortedKeys($fresh->after ?? []))->toBe($expected)
        ->and(withSortedKeys($fresh->changes ?? []))->toBe($expected)
        ->and(withSortedKeys($fresh->metadata ?? []))->toBe($expected)
        ->and(withSortedKeys($fresh->encryption ?? []))->toBe($expected)
        ->and(withSortedKeys($fresh->criteria ?? []))->toBe($expected);
})->with([
    'empty' => [[]],
    'flat' => [['ip' => '10.0.0.1']],
    'nested' => [['job' => ['queue' => 'audits', 'attempts' => 3, 'payload' => ['a' => [1, 2]]]]],
]);

it('tells an absent json column from an empty one', function (): void {
    insertAudit(['before' => null, 'after' => '{}']);

    $audit = Audit::query()->firstOrFail();

    expect($audit->before)->toBeNull()
        ->and($audit->after)->toBe([]);
});

it('resolves an integer keyed subject through its morph', function (): void {
    $subject = IntKeySubject::query()->create();

    insertAudit(['subject_type' => $subject::class, 'subject_id' => (string) $subject->getKey()]);

    expect(Audit::query()->firstOrFail()->subject)->toBeInstanceOf(IntKeySubject::class);
});

it('resolves a uuid keyed subject through its morph', function (): void {
    $subject = UuidKeySubject::query()->create();

    insertAudit(['subject_type' => $subject::class, 'subject_id' => (string) $subject->getKey()]);

    expect(Audit::query()->firstOrFail()->subject)->toBeInstanceOf(UuidKeySubject::class);
});

it('resolves a ulid keyed subject through its morph', function (): void {
    $subject = UlidKeySubject::query()->create();

    insertAudit(['subject_type' => $subject::class, 'subject_id' => (string) $subject->getKey()]);

    expect(Audit::query()->firstOrFail()->subject)->toBeInstanceOf(UlidKeySubject::class);
});

it('resolves the actor and the impersonator through their own morphs', function (): void {
    $actor = IntKeySubject::query()->create();
    $impersonator = IntKeySubject::query()->create();

    insertAudit([
        'actor_type' => $actor::class,
        'actor_id' => (string) $actor->getKey(),
        'impersonator_type' => $impersonator::class,
        'impersonator_id' => (string) $impersonator->getKey(),
    ]);

    $audit = Audit::query()->firstOrFail();

    expect($audit->actor)->toBeInstanceOf(IntKeySubject::class)
        ->and($audit->impersonator)->toBeInstanceOf(IntKeySubject::class);
});

it('leaves a morph null when nothing was recorded', function (): void {
    insertAudit();

    expect(Audit::query()->firstOrFail()->subject)->toBeNull();
});

it('returns its own collection type', function (): void {
    insertAudit();

    expect(Audit::query()->get())->toBeInstanceOf(AuditCollection::class);
});

it('keeps microsecond precision on occurred_at', function (): void {
    insertAudit(['occurred_at' => '2026-08-26 10:00:00.123456']);

    expect(Audit::query()->firstOrFail()->occurred_at->format('u'))->toBe('123456');
});

it('writes microseconds back when it is written', function (): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->merge([
        'occurred_at' => CarbonImmutable::parse('2026-08-26 10:00:00.654321'),
    ])->all());

    expect(DB::table(auditsTable())->where('id', $audit->id)->value('occurred_at'))
        ->toContain('.654321');
});

it('lets the configured subclass read the same table', function (): void {
    DB::table(auditsTable())->insert(auditRow(['sequence' => 7]));

    expect(CustomAudit::query()->firstOrFail()->sequence)->toBe(7);
});
