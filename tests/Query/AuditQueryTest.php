<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\LimitedLedger;

use function ElPandaPe\Sentinel\Tests\auditQuery;

it('starts with nothing to filter by and the oldest entry first', function (): void {
    $query = auditQuery();

    expect($query->subject)->toBeNull()
        ->and($query->actor)->toBeNull()
        ->and($query->event)->toBeNull()
        ->and($query->severity)->toBeNull()
        ->and($query->source)->toBeNull()
        ->and($query->tenantId)->toBeNull()
        ->and($query->transactionId)->toBeNull()
        ->and($query->traceId)->toBeNull()
        ->and($query->period)->toBeNull()
        ->and($query->newestFirst)->toBeFalse();
});

it('leaves the query it came from untouched', function (): void {
    $query = auditQuery();

    $narrowed = $query->forTenant('acme');

    expect($narrowed)->not->toBe($query)
        ->and($narrowed->tenantId)->toBe('acme')
        ->and($query->tenantId)->toBeNull();
});

it('narrows to a subject by model', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    $query = auditQuery()->for($subject);

    expect($query->subject?->type)->toBe(AuditedSubject::class)
        ->and($query->subject?->id)->toBe((string) $subject->getKey())
        ->and($query->actor)->toBeNull();
});

it('narrows to a subject that no longer exists by its recorded type and key', function (): void {
    $query = auditQuery()->for(AuditedSubject::class, 7);

    expect($query->subject?->type)->toBe(AuditedSubject::class)
        ->and($query->subject?->id)->toBe('7');
});

it('narrows to an actor', function (): void {
    $actor = ActingUser::query()->create(['name' => 'Grace']);

    $query = auditQuery()->by($actor);

    expect($query->actor?->type)->toBe(ActingUser::class)
        ->and($query->actor?->id)->toBe((string) $actor->getKey())
        ->and($query->subject)->toBeNull();
});

it('resolves the short form and the explicit one to the same filter', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $actor = ActingUser::query()->create(['name' => 'Grace']);

    expect(auditQuery()->forModel($subject)->subject)->toEqual(auditQuery()->for($subject)->subject)
        ->and(auditQuery()->byActor($actor)->actor)->toEqual(auditQuery()->by($actor)->actor);
});

it('narrows to an event named by the enum or by the string a custom event recorded', function (): void {
    expect(auditQuery()->whereEvent(AuditEvent::Deleted)->event)->toBe('deleted')
        ->and(auditQuery()->whereEvent('invoice.approved')->event)->toBe('invoice.approved');
});

it('narrows to a severity, a source, a transaction and a trace', function (): void {
    expect(auditQuery()->whereSeverity(Severity::Critical)->severity)->toBe(Severity::Critical)
        ->and(auditQuery()->whereSource(Source::Queue)->source)->toBe(Source::Queue)
        ->and(auditQuery()->inTransaction('01JTRANSACTION000000000000')->transactionId)->toBe('01JTRANSACTION000000000000')
        ->and(auditQuery()->withTrace('4bf92f3577b34da6a3ce929d0e0e4736')->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('keeps a period as an immutable pair whatever kind of date it was given', function (): void {
    $query = auditQuery()->between(
        new DateTime('2026-08-01 00:00:00'),
        new DateTimeImmutable('2026-08-31 23:59:59'),
    );

    expect($query->period?->from)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($query->period?->from->format('Y-m-d H:i:s'))->toBe('2026-08-01 00:00:00')
        ->and($query->period?->to->format('Y-m-d H:i:s'))->toBe('2026-08-31 23:59:59');
});

it('covers both ends of the period it was given', function (): void {
    $period = auditQuery()->between(
        new DateTimeImmutable('2026-08-01 00:00:00'),
        new DateTimeImmutable('2026-08-31 23:59:59'),
    )->period;

    expect($period?->covers(new DateTimeImmutable('2026-08-01 00:00:00')))->toBeTrue()
        ->and($period?->covers(new DateTimeImmutable('2026-08-31 23:59:59')))->toBeTrue()
        ->and($period?->covers(new DateTimeImmutable('2026-07-31 23:59:59')))->toBeFalse()
        ->and($period?->covers(new DateTimeImmutable('2026-09-01 00:00:00')))->toBeFalse();
});

it('refuses a period that ends before it starts', function (): void {
    expect(fn (): AuditQuery => auditQuery()->between(
        new DateTimeImmutable('2026-08-31 23:59:59'),
        new DateTimeImmutable('2026-08-01 00:00:00'),
    ))->toThrow(QueryException::class, 'can only ever answer with nothing');
});

it('turns the order around on request', function (): void {
    expect(auditQuery()->latest()->newestFirst)->toBeTrue();
});

it('keeps every criterion it was given as it is narrowed', function (): void {
    $query = auditQuery()
        ->for(AuditedSubject::class, 7)
        ->whereSeverity(Severity::Warning)
        ->forTenant('acme')
        ->latest();

    expect($query->subject?->id)->toBe('7')
        ->and($query->severity)->toBe(Severity::Warning)
        ->and($query->tenantId)->toBe('acme')
        ->and($query->newestFirst)->toBeTrue();
});

it('refuses a filter the ledger cannot translate, as the filter is added', function (): void {
    $query = new AuditQuery(new LimitedLedger);

    expect(fn (): AuditQuery => $query->forTenant('acme'))
        ->toThrow(LedgerException::class, 'cannot filter by tenant, so forTenant() is not part of')
        ->and($query->for(AuditedSubject::class, 7)->subject?->id)->toBe('7');
});

it('names the method that reaches each filter', function (): void {
    expect(array_map(static fn (Filter $filter): string => $filter->method(), Filter::cases()))
        ->toBe(['for', 'by', 'whereEvent', 'whereSeverity', 'whereSource', 'forTenant', 'inTransaction', 'withTrace', 'between']);
});
