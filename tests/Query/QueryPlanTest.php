<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\planFor;
use function ElPandaPe\Sentinel\Tests\reachesByIndex;
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
    'type' => [fn (): AuditQuery => Sentinel::audits()->whereType('transition')],
]);

it('reaches an index for every filter the readme does not call a refiner', function (Closure $narrow): void {
    $plan = planFor($narrow());

    expect(readsAnIndex($plan))->toBeTrue($plan);
})->with('the filters that find');

it('still reaches that index when the newest entry is asked for first', function (Closure $narrow): void {
    $plan = planFor($narrow()->latest());

    expect(readsAnIndex($plan))->toBeTrue($plan);
})->with('the filters that find');

it('reaches no index at all for a source, which is why it is a refiner', function (): void {
    $plan = planFor(Sentinel::audits()->whereSource(Source::Cli));

    expect(readsAnIndex($plan))->toBeFalse($plan);
});

it('reaches no index for a period on its own, unless the engine skip scans one that is not about it', function (): void {
    $plan = planFor(Sentinel::audits()->between(
        new DateTimeImmutable('2026-08-01 00:00:00'),
        new DateTimeImmutable('2026-08-01 00:05:00'),
    ));

    expect(readsAnIndex($plan))->toBe(DB::connection()->getDriverName() === 'sqlite', $plan);
});

it('rides the index of the filter in front of it once a refiner has one', function (Closure $narrow): void {
    $narrowed = $narrow()
        ->whereSource(Source::Cli)
        ->between(new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-09-01 00:00:00'));

    $plan = planFor($narrowed);

    expect(readsAnIndex($plan))->toBeTrue($plan);
})->with('the filters that find');

it('pays for a whole pass and a sort when nothing narrows it, which is why get is bounded', function (): void {
    $plan = planFor(Sentinel::audits()->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($plan))->toBeFalse($plan)
        ->and(sortsOutsideTheIndex($plan))->toBeTrue($plan);
});

/**
 * The question a label filter has to answer is whether asking for a label is a seek into the
 * labels table rather than a walk of it, not whether the trail was scanned: with five matching
 * labels against six hundred entries, joining the index result to a scan of the small side is the
 * right plan and PostgreSQL picks it.
 *
 * Only the intersection is asserted. A union over a labels table this small is cheaper to scan
 * than to seek on PostgreSQL, which is a fact about the fixture rather than about the index, and
 * pinning it would be a gate that moves with the data.
 */
it('reaches the labels table through an index for a label worth narrowing by', function (): void {
    $plan = planFor(Sentinel::audits()->whereTag('audited'));

    expect(reachesByIndex($plan, auditTagsTable()))->toBeTrue($plan);
});

/**
 * What each planner does with an unnarrowed timeline, which is not the same answer everywhere and
 * is not stable with size. SQLite commits to the index for this clock. MySQL and PostgreSQL are
 * cost-based: at suite size they would rather read the whole table and top-N sort it, and the
 * index only wins once the table is large enough that it does not — measured at two hundred
 * thousand entries, where it takes MySQL from 100ms to 0.39ms and PostgreSQL from 12.9ms to
 * 0.23ms. Asserting the large-table plan here would be a gate that stops being true the moment
 * the fixture shrinks, so what is asserted is what each engine really does at this size.
 */
it('leaves the clock of the fact to a cost-based decision on two of the three engines', function (): void {
    $plan = planFor(Sentinel::timeline()->take(AuditQuery::DEFAULT_LIMIT));

    expect(sortsOutsideTheIndex($plan))->toBe(DB::connection()->getDriverName() !== 'sqlite', $plan);
});

it('rides an index for the timeline of one subject too', function (): void {
    $plan = planFor(Sentinel::timeline()->for('invoice', 7));

    expect(sortsOutsideTheIndex($plan))->toBeFalse($plan)
        ->and(readsAnIndex($plan))->toBeTrue($plan);
});

it('reaches no index at all for a changed field, which is why it is a refiner', function (): void {
    $narrowed = planFor(Sentinel::audits()->for('invoice', 7)->whereFieldChanged('email'));
    $alone = planFor(Sentinel::audits()->whereFieldChanged('email')->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($narrowed))->toBeTrue($narrowed)
        ->and(readsAnIndex($alone))->toBeFalse($alone);
});

/**
 * The two that live inside the context are refiners until the JSON index migration is published,
 * which is what makes that migration opt-in rather than a cost every installation pays.
 */
it('reaches no index for a filter inside the context until its migration is published', function (Closure $narrow): void {
    $alone = planFor($narrow()->take(AuditQuery::DEFAULT_LIMIT));
    $behind = planFor(Sentinel::audits()->for('invoice', 7)->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($alone))->toBeFalse($alone)
        ->and(readsAnIndex($behind))->toBeTrue($behind);
})->with([
    'address' => [fn (): AuditQuery => Sentinel::audits()->whereIp('203.0.113.7')],
    'route' => [fn (): AuditQuery => Sentinel::audits()->whereRoute('invoices.show')],
]);

it('reaches no index at all for a version, which is why it is a refiner', function (): void {
    $plan = planFor(Sentinel::audits()->whereVersion(3)->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($plan))->toBeFalse($plan);
});

/**
 * The same question the label index answers, asked of the relation projection: did the engine seek
 * into it, or walk it. Which of its three indexes answered is the planner's business and moves with
 * the engine's version, so what is asserted is the seek — the lesson v0.10.1 was published for.
 */
it('reaches the relation projection through an index for a relation worth narrowing by', function (): void {
    $plan = planFor(Sentinel::audits()->whereRelation('members'));

    expect(reachesByIndex($plan, auditRelationsTable()))->toBeTrue($plan);
});

it('reaches it through an index for the record that was related too', function (): void {
    $plan = planFor(Sentinel::audits()->whereRelated('member', 0)->whereOperation('attach'));

    expect(reachesByIndex($plan, auditRelationsTable()))->toBeTrue($plan);
});

/**
 * The lifeline of one record narrows by two indexed columns at once, and a planner picks one
 * index, not both: the subject composite finds the record and leaves the clock to be sorted, or
 * the type composite arrives sorted and filters the subject. Which one it picks is its business
 * and moves with the engine's version, so what is asserted is that it seeks rather than walks —
 * the same thing every other index gate here asserts.
 */
it('rides an index for the lifeline of one subject', function (): void {
    $plan = planFor(Sentinel::audits()->for('invoice', 7)->whereType('transition'));

    expect(readsAnIndex($plan))->toBeTrue($plan);
});
