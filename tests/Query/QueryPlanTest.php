<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\planFor;
use function ElPandaPe\Sentinel\Tests\readsAnIndex;
use function ElPandaPe\Sentinel\Tests\seedTheTrail;
use function ElPandaPe\Sentinel\Tests\sortsOutsideTheIndex;

beforeEach(function (): void {
    seedTheTrail();
});

dataset('the filters that find', [
    'subject' => [fn (): AuditQuery => Sentinel::audits()->for('invoice', 7)],
    'actor' => [fn (): AuditQuery => Sentinel::audits()->by('user', 7)],
    'event' => [fn (): AuditQuery => Sentinel::audits()->whereEvent('event.7')],
    'severity' => [fn (): AuditQuery => Sentinel::audits()->whereSeverity(Severity::Critical)],
    'tenant' => [fn (): AuditQuery => Sentinel::audits()->forTenant('tenant-7')],
    'transaction' => [fn (): AuditQuery => Sentinel::audits()->inTransaction(str_pad('7', 26, '0', STR_PAD_LEFT))],
    'trace' => [fn (): AuditQuery => Sentinel::audits()->withTrace(str_pad('7', 32, '0', STR_PAD_LEFT))],
]);

it('reaches an index for every filter the readme does not call a refiner', function (Closure $narrow): void {
    expect(readsAnIndex(planFor($narrow())))->toBeTrue();
})->with('the filters that find');

it('still reaches that index when the newest entry is asked for first', function (Closure $narrow): void {
    expect(readsAnIndex(planFor($narrow()->latest())))->toBeTrue();
})->with('the filters that find');

it('reaches no index at all for a source, which is why it is a refiner', function (): void {
    expect(readsAnIndex(planFor(Sentinel::audits()->whereSource(Source::Cli))))->toBeFalse();
});

it('reaches no index for a period on its own, unless the engine skip scans one that is not about it', function (): void {
    $plan = planFor(Sentinel::audits()->between(
        new DateTimeImmutable('2026-08-01 00:00:00'),
        new DateTimeImmutable('2026-08-01 00:05:00'),
    ));

    expect(readsAnIndex($plan))->toBe(DB::connection()->getDriverName() === 'sqlite');
});

it('rides the index of the filter in front of it once a refiner has one', function (Closure $narrow): void {
    $narrowed = $narrow()
        ->whereSource(Source::Cli)
        ->between(new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-09-01 00:00:00'));

    expect(readsAnIndex(planFor($narrowed)))->toBeTrue();
})->with('the filters that find');

it('pays for a whole pass and a sort when nothing narrows it, which is why get is bounded', function (): void {
    $plan = planFor(Sentinel::audits()->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($plan))->toBeFalse()
        ->and(sortsOutsideTheIndex($plan))->toBeTrue();
});

it('reaches the reversed label index for a label worth narrowing by', function (): void {
    expect(readsAnIndex(planFor(Sentinel::audits()->whereTag('audited'))))->toBeTrue();
});

it('rides an index for the clock of the fact instead of sorting outside one', function (): void {
    $plan = planFor(Sentinel::timeline()->take(AuditQuery::DEFAULT_LIMIT));

    expect(sortsOutsideTheIndex($plan))->toBeFalse()
        ->and(readsAnIndex($plan))->toBeTrue();
});

it('rides an index for the timeline of one subject too', function (): void {
    $plan = planFor(Sentinel::timeline()->for('invoice', 7));

    expect(sortsOutsideTheIndex($plan))->toBeFalse()
        ->and(readsAnIndex($plan))->toBeTrue();
});

it('reaches no index at all for a changed field, which is why it is a refiner', function (): void {
    expect(readsAnIndex(planFor(Sentinel::audits()->for('invoice', 7)->whereFieldChanged('email'))))->toBeTrue()
        ->and(readsAnIndex(planFor(Sentinel::audits()->whereFieldChanged('email')->take(AuditQuery::DEFAULT_LIMIT))))->toBeFalse();
});

it('reaches no index at all for a version, which is why it is a refiner', function (): void {
    expect(readsAnIndex(planFor(Sentinel::audits()->whereVersion(3)->take(AuditQuery::DEFAULT_LIMIT))))->toBeFalse();
});
