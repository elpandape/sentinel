<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Testing;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use Orchestra\Testbench\TestCase;

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

    /**
     * The capture every expectation here is written from. A driver that needs a different
     * one overrides this; the suite only ever varies the stream, because the stream is the
     * only field the chain is scoped by.
     */
    protected function auditData(?string $stream = null): AuditData
    {
        return new AuditData(
            audit_type: 'model',
            event: 'created',
            severity: Severity::Info,
            occurred_at: new DateTimeImmutable('2026-08-26 10:00:00.000000'),
            stream: $stream,
        );
    }
}
