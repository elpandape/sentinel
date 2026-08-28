<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Testing;

use Closure;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The expectations every ledger has to meet, whatever it writes to. Extend it, return your
 * driver from ledger(), and the driver is held to the same chain the ones in this package
 * are: a dense, monotonic sequence per stream, each entry linked to the one before. That is
 * what the contract guarantees, so that is all this asserts — nothing here reaches for a
 * table, and a driver over a document store runs it unchanged.
 *
 * Two hooks let a driver say what it is rather than fail for being it. A ledger that keeps
 * nothing answers false to retains(). A ledger whose reads are eventually consistent makes
 * its writes visible in settle().
 *
 * It ships in src/ rather than in require-dev on purpose: a contract nobody outside this
 * package can execute is a promise, not a verification. PHPUnit and Testbench stay dev
 * dependencies, declared in `suggest` — install them and this runs.
 */
abstract class LedgerContractTestCase extends TestCase
{
    public function test_it_starts_a_chain_with_no_previous_link(): void
    {
        $audit = $this->ledger()->write($this->auditData());

        $this->assertSame(1, $audit->sequence);
        $this->assertNull($audit->previous_hash);
        $this->assertSame(1, $audit->payload_version);
    }

    public function test_it_links_every_entry_to_the_one_before(): void
    {
        $ledger = $this->ledger();

        $first = $ledger->write($this->auditData());
        $second = $ledger->write($this->auditData());

        $this->assertSame(2, $second->sequence);
        $this->assertSame($first->hash, $second->previous_hash);
    }

    public function test_it_numbers_each_stream_on_its_own(): void
    {
        $ledger = $this->ledger();

        $ledger->write($this->auditData('alpha'));
        $beta = $ledger->write($this->auditData('beta'));

        $this->assertSame(1, $beta->sequence);
        $this->assertNull($beta->previous_hash);
    }

    public function test_it_consumes_consecutive_sequences_for_a_batch(): void
    {
        $written = $this->ledger()->writeMany([$this->auditData(), $this->auditData(), $this->auditData()]);

        $this->assertSame([1, 2, 3], $written->pluck('sequence')->all());
    }

    public function test_it_writes_nothing_for_an_empty_batch(): void
    {
        $this->assertSame([], $this->ledger()->writeMany([])->all());
    }

    public function test_it_stores_an_entry_it_did_not_seal(): void
    {
        $sealed = $this->sealedElsewhere();
        $appended = $this->ledger()->append($sealed);

        $this->assertSame($sealed->sequence, $appended->sequence);
        $this->assertSame($sealed->hash, $appended->hash);
        $this->assertSame($sealed->previous_hash, $appended->previous_hash);
    }

    public function test_it_walks_a_stream_in_order(): void
    {
        $ledger = $this->ledger();
        $ledger->writeMany([$this->auditData(), $this->auditData(), $this->auditData()]);
        $this->settle($ledger);

        $sequences = [];

        foreach ($ledger->stream('global') as $audit) {
            $sequences[] = $audit->sequence;
        }

        $this->assertSame($this->retains() ? [1, 2, 3] : [], $sequences);
    }

    public function test_it_walks_a_bounded_range_of_a_stream(): void
    {
        $ledger = $this->ledger();
        $ledger->writeMany([$this->auditData(), $this->auditData(), $this->auditData()]);
        $this->settle($ledger);

        $sequences = [];

        foreach ($ledger->stream('global')->range(2, 3) as $audit) {
            $sequences[] = $audit->sequence;
        }

        $this->assertSame($this->retains() ? [2, 3] : [], $sequences);
    }

    public function test_it_walks_an_empty_stream(): void
    {
        $this->assertSame([], iterator_to_array($this->ledger()->stream('nonesuch')));
    }

    public function test_it_finds_what_it_wrote(): void
    {
        $ledger = $this->ledger();
        $audit = $ledger->write($this->auditData());
        $this->settle($ledger);

        $this->assertSame($this->retains() ? $audit->hash : null, $ledger->find($audit->id)?->hash);
    }

    public function test_it_gives_an_appended_entry_back_unchanged(): void
    {
        $ledger = $this->ledger();
        $sealed = $this->sealedElsewhere();
        $ledger->append($sealed);
        $this->settle($ledger);

        $this->assertSame($this->retains() ? $sealed->hash : null, $ledger->find($sealed->id)?->hash);
    }

    public function test_it_finds_nothing_for_an_unknown_id(): void
    {
        $this->assertNull($this->ledger()->find('01JXXXXXXXXXXXXXXXXXXXXXXX'));
    }

    /**
     * @param  Closure(AuditQuery): AuditQuery  $narrow
     */
    #[DataProvider('publishedFilters')]
    public function test_it_answers_with_what_a_filter_matches_and_nothing_else(Filter $filter, Closure $narrow): void
    {
        $ledger = $this->ledger();
        $ledger->write($this->auditData());
        $wanted = $ledger->write($this->narrowedAuditData());
        $this->settle($ledger);

        if (! $this->translates($ledger, $filter)) {
            $this->expectException(LedgerException::class);
        }

        $found = $ledger->query($narrow($this->asking()));

        $this->assertSame($this->retains() ? [$wanted->id] : [], $found->pluck('id')->all());
    }

    /**
     * Each row names the filter it exercises, so a driver that declares a narrow set is skipped
     * for the ones it never claimed instead of erroring on a refusal it asked for.
     *
     * @return array<string, array{Filter, Closure(AuditQuery): AuditQuery}>
     */
    public static function publishedFilters(): array
    {
        return [
            'subject' => [Filter::Subject, static fn (AuditQuery $query): AuditQuery => $query->for('invoice', 500)],
            'actor' => [Filter::Actor, static fn (AuditQuery $query): AuditQuery => $query->by('user', 1)],
            'event' => [Filter::Event, static fn (AuditQuery $query): AuditQuery => $query->whereEvent('approved')],
            'severity' => [Filter::Severity, static fn (AuditQuery $query): AuditQuery => $query->whereSeverity(Severity::Critical)],
            'source' => [Filter::Source, static fn (AuditQuery $query): AuditQuery => $query->whereSource(Source::Queue)],
            'tenant' => [Filter::Tenant, static fn (AuditQuery $query): AuditQuery => $query->forTenant('acme')],
            'transaction' => [Filter::Transaction, static fn (AuditQuery $query): AuditQuery => $query->inTransaction('01JTRANSACTION000000000000')],
            'trace' => [Filter::Trace, static fn (AuditQuery $query): AuditQuery => $query->withTrace('4bf92f3577b34da6a3ce929d0e0e4736')],
            'every label' => [Filter::Tag, static fn (AuditQuery $query): AuditQuery => $query->whereTag(['billing', 'refund'])],
            'any label' => [Filter::Tag, static fn (AuditQuery $query): AuditQuery => $query->whereAnyTag(['refund', 'absent'])],
            'changed field' => [Filter::FieldChanged, static fn (AuditQuery $query): AuditQuery => $query->whereFieldChanged('total')],
            'changed field beneath a parent' => [Filter::FieldChanged, static fn (AuditQuery $query): AuditQuery => $query->whereFieldChanged('profile')],
            'version' => [Filter::Version, static fn (AuditQuery $query): AuditQuery => $query->whereVersion(1)],
        ];
    }

    public function test_it_hands_an_entry_back_carrying_the_labels_it_was_written_with(): void
    {
        $written = $this->ledger()->write($this->narrowedAuditData());

        $this->assertSame(
            ['billing', 'refund'],
            array_map(static fn (AuditTag $tag): string => $tag->tag, $written->tags->all()),
        );
    }

    public function test_it_narrows_to_an_entry_carrying_every_label_asked_for(): void
    {
        $ledger = $this->ledger();
        $ledger->write($this->auditData());
        $wanted = $ledger->write($this->narrowedAuditData());
        $this->settle($ledger);

        if (! $this->translates($ledger, Filter::Tag)) {
            $this->expectException(LedgerException::class);
        }

        $found = $ledger->query($this->asking()->whereTag('billing')->whereTag('refund'));

        $this->assertSame($this->retains() ? [$wanted->id] : [], $found->pluck('id')->all());
    }

    public function test_it_answers_nothing_for_a_label_no_entry_carries(): void
    {
        $ledger = $this->ledger();
        $ledger->write($this->narrowedAuditData());
        $this->settle($ledger);

        if (! $this->translates($ledger, Filter::Tag)) {
            $this->expectException(LedgerException::class);
        }

        $this->assertSame([], $ledger->query($this->asking()->whereTag('absent'))->pluck('id')->all());
    }

    /**
     * The two clocks are asserted against the same three entries, written in an order that is
     * the reverse of when they happened. An expectation built on entries stamped alike would
     * pass whether the criterion did anything or not.
     */
    public function test_it_orders_by_the_clock_of_the_fact_when_asked_to(): void
    {
        $ledger = $this->ledger();
        $written = [
            $ledger->write($this->auditData(occurredAt: '2026-08-26 12:00:00.000000'))->id,
            $ledger->write($this->auditData(occurredAt: '2026-08-26 11:00:00.000000'))->id,
            $ledger->write($this->auditData(occurredAt: '2026-08-26 10:00:00.000000'))->id,
        ];
        $this->settle($ledger);

        $recorded = $ledger->query($this->asking())->pluck('id')->all();
        $occurred = $ledger->query($this->asking()->byOccurrence())->pluck('id')->all();

        $this->assertSame($this->retains() ? $written : [], $recorded);
        $this->assertSame($this->retains() ? array_reverse($written) : [], $occurred);
    }

    public function test_it_turns_the_clock_of_the_fact_around_on_request(): void
    {
        $ledger = $this->ledger();
        $written = [
            $ledger->write($this->auditData(occurredAt: '2026-08-26 12:00:00.000000'))->id,
            $ledger->write($this->auditData(occurredAt: '2026-08-26 10:00:00.000000'))->id,
        ];
        $this->settle($ledger);

        $found = $ledger->query($this->asking()->byOccurrence()->latest())->pluck('id')->all();

        $this->assertSame($this->retains() ? $written : [], $found);
    }

    public function test_it_refuses_a_read_that_would_come_back_looking_complete(): void
    {
        $ledger = $this->ledger();

        for ($written = 0; $written <= AuditQuery::DEFAULT_LIMIT; $written++) {
            $ledger->write($this->auditData());
        }

        $this->settle($ledger);

        if (! $this->retains()) {
            $this->assertCount(0, $this->asking()->get());

            return;
        }
        expect(fn () => $this->asking()->get())->toThrow(QueryException::class);
    }

    public function test_it_answers_an_unnarrowed_query_with_everything_it_kept(): void
    {
        $ledger = $this->ledger();
        $written = [
            $ledger->write($this->auditData())->id,
            $ledger->write($this->auditData('beta'))->id,
            $ledger->write($this->auditData())->id,
        ];
        $this->settle($ledger);

        $found = $ledger->query($this->asking());

        $this->assertSame($this->retains() ? $written : [], $found->pluck('id')->all());
    }

    public function test_it_gives_the_oldest_entry_first_and_the_newest_first_on_request(): void
    {
        $ledger = $this->ledger();
        $written = $ledger->writeMany([$this->auditData(), $this->auditData(), $this->auditData()]);
        $this->settle($ledger);

        $ids = $written->pluck('id')->all();

        $this->assertSame(
            $this->retains() ? array_reverse($ids) : [],
            $ledger->query($this->asking()->latest())->pluck('id')->all(),
        );
    }

    public function test_it_bounds_a_period_by_both_of_its_ends(): void
    {
        $ledger = $this->ledger();

        $this->travelTo('2026-08-01 10:00:00');
        $ledger->write($this->auditData());
        $this->travelTo('2026-08-15 10:00:00');
        $inside = $ledger->write($this->auditData());
        $this->travelTo('2026-08-31 10:00:00');
        $ledger->write($this->auditData());
        $this->settle($ledger);

        $found = $ledger->query($this->asking()->between(
            new DateTimeImmutable('2026-08-15 10:00:00'),
            new DateTimeImmutable('2026-08-20 00:00:00'),
        ));

        $this->assertSame($this->retains() ? [$inside->id] : [], $found->pluck('id')->all());
    }

    public function test_it_narrows_to_one_subject_inside_a_period_newest_first(): void
    {
        $ledger = $this->ledger();

        $this->travelTo('2026-08-15 10:00:00');
        $ledger->write($this->auditData());
        $first = $ledger->write($this->narrowedAuditData());
        $second = $ledger->write($this->narrowedAuditData());
        $this->settle($ledger);

        $found = $ledger->query($this->asking()
            ->for('invoice', 500)
            ->between(new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-08-31 23:59:59'))
            ->latest());

        $this->assertSame($this->retains() ? [$second->id, $first->id] : [], $found->pluck('id')->all());
    }

    public function test_it_narrows_by_a_tenant_and_a_severity_at_once(): void
    {
        $ledger = $this->ledger();
        $ledger->write($this->narrowedAuditData(severity: Severity::Info));
        $wanted = $ledger->write($this->narrowedAuditData());
        $this->settle($ledger);

        $found = $ledger->query($this->asking()->forTenant('acme')->whereSeverity(Severity::Critical));

        $this->assertSame($this->retains() ? [$wanted->id] : [], $found->pluck('id')->all());
    }

    public function test_it_answers_one_page_at_a_time_and_says_whether_another_follows(): void
    {
        $ledger = $this->ledger();
        $written = [
            $ledger->write($this->auditData())->id,
            $ledger->write($this->auditData())->id,
            $ledger->write($this->auditData())->id,
        ];
        $this->settle($ledger);

        $first = $this->asking()->paginate(2);
        $second = $this->asking()->paginate(2, 2);

        $this->assertSame($this->retains() ? array_slice($written, 0, 2) : [], $first->entries->pluck('id')->all());
        $this->assertSame($this->retains(), $first->hasMore);
        $this->assertSame($this->retains() ? array_slice($written, 2) : [], $second->entries->pluck('id')->all());
        $this->assertFalse($second->hasMore);
    }

    public function test_it_walks_one_transaction_newest_first(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->write($this->narrowedAuditData());
        $second = $ledger->write($this->narrowedAuditData());
        $ledger->write($this->auditData());
        $this->settle($ledger);

        $found = $ledger->query($this->asking()->inTransaction('01JTRANSACTION000000000000')->latest());

        $this->assertSame($this->retains() ? [$second->id, $first->id] : [], $found->pluck('id')->all());
    }

    /**
     * A driver is held to one of two answers for every published filter, never to neither: it
     * translates the filter, or it refuses it as the query is built. Skipping the expectation
     * for a filter a driver does not declare would leave the third answer — dropping it in
     * silence — the only one this contract never checks, and it is the one it exists to forbid.
     */
    protected function translates(Ledger $ledger, Filter $filter): bool
    {
        return in_array($filter, Filter::answeredBy($ledger), true);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SentinelServiceProvider::class];
    }

    /**
     * Testbench defines no application key, and the package derives the digest salt and the
     * default encryption key from it. Fixed rather than generated: a key that moves between
     * runs makes every assertion about a digest compare a value to a different value.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_pad('sentinel-contract-key', 32, '0')));
    }

    abstract protected function ledger(): Ledger;

    /**
     * Whether find() and stream() give back what the ledger was handed. It chooses which
     * expectation applies, never whether one does: a driver that answers false is held to
     * keeping nothing, as strictly as the others are held to keeping everything.
     */
    protected function retains(): bool
    {
        return true;
    }

    /**
     * Called between a write and the read that checks it. A driver whose reads are eventually
     * consistent makes what was just written visible here; one that reads its own writes has
     * nothing to do. The contract promises no read sees a write that just returned, so a
     * driver that needs this is honouring it, not working around it.
     */
    protected function settle(Ledger $ledger): void {}

    /**
     * An entry another ledger sealed. append() has to take it exactly as it is, so building
     * it here rather than writing it is the point: nothing about it belongs to the ledger
     * under test.
     */
    protected function sealedElsewhere(): Audit
    {
        return app(EntryBuilder::class)->build($this->auditData(), 'imported', 1, null, null);
    }

    protected function asking(): AuditQuery
    {
        return new AuditQuery($this->ledger());
    }

    /**
     * The capture the query expectations narrow to: every published filter matches it and
     * none of them matches the plain one, so a filter that quietly stopped narrowing shows up
     * as an entry nobody asked for rather than as a passing test.
     */
    protected function narrowedAuditData(Severity $severity = Severity::Critical): AuditData
    {
        return new AuditData(
            audit_type: 'model',
            event: 'approved',
            severity: $severity,
            occurred_at: new DateTimeImmutable('2026-08-26 10:00:00.000000'),
            source: Source::Queue,
            subject_type: 'invoice',
            subject_id: '500',
            actor_type: 'user',
            actor_id: '1',
            tenant_id: 'acme',
            transaction_id: '01JTRANSACTION000000000000',
            trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
            changes: [
                ['path' => '/total', 'op' => 'replace', 'old' => 100, 'new' => 250],
                ['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
            ],
            tags: ['billing', 'refund'],
        );
    }

    /**
     * The capture every expectation here is written from. A driver that needs a different
     * one overrides this; the suite only ever varies the stream, because the stream is the
     * only field the chain is scoped by.
     */
    protected function auditData(?string $stream = null, string $occurredAt = '2026-08-26 10:00:00.000000'): AuditData
    {
        return new AuditData(
            audit_type: 'model',
            event: 'created',
            severity: Severity::Info,
            occurred_at: new DateTimeImmutable($occurredAt),
            stream: $stream,
        );
    }
}
