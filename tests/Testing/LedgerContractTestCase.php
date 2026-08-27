<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\TestCase;

use function ElPandaPe\Sentinel\Tests\auditData;

/**
 * The expectations every ledger has to meet, whatever it writes to. What is asserted here is
 * the chain — a dense, monotonic sequence per stream, each entry linked to the one before —
 * because that is what the contract actually guarantees. Nothing here reaches for a table:
 * a driver over a document store has to be able to run this suite unchanged.
 *
 * Two hooks let a driver say what it is rather than fail for being it. A ledger that keeps
 * nothing answers false to retains(). A ledger whose reads are eventually consistent makes
 * its writes visible in settle().
 */
abstract class LedgerContractTestCase extends TestCase
{
    public function test_it_starts_a_chain_with_no_previous_link(): void
    {
        $audit = $this->ledger()->write(auditData());

        $this->assertSame(1, $audit->sequence);
        $this->assertNull($audit->previous_hash);
        $this->assertSame(1, $audit->payload_version);
    }

    public function test_it_links_every_entry_to_the_one_before(): void
    {
        $ledger = $this->ledger();

        $first = $ledger->write(auditData());
        $second = $ledger->write(auditData());

        $this->assertSame(2, $second->sequence);
        $this->assertSame($first->hash, $second->previous_hash);
    }

    public function test_it_numbers_each_stream_on_its_own(): void
    {
        $ledger = $this->ledger();

        $ledger->write(auditData(['stream' => 'alpha']));
        $beta = $ledger->write(auditData(['stream' => 'beta']));

        $this->assertSame(1, $beta->sequence);
        $this->assertNull($beta->previous_hash);
    }

    public function test_it_consumes_consecutive_sequences_for_a_batch(): void
    {
        $written = $this->ledger()->writeMany([auditData(), auditData(), auditData()]);

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
        $ledger = $this->retaining();
        $ledger->writeMany([auditData(), auditData(), auditData()]);
        $this->settle($ledger);

        $sequences = [];

        foreach ($ledger->stream('global') as $audit) {
            $sequences[] = $audit->sequence;
        }

        $this->assertSame([1, 2, 3], $sequences);
    }

    public function test_it_walks_a_bounded_range_of_a_stream(): void
    {
        $ledger = $this->retaining();
        $ledger->writeMany([auditData(), auditData(), auditData()]);
        $this->settle($ledger);

        $sequences = [];

        foreach ($ledger->stream('global')->range(2, 3) as $audit) {
            $sequences[] = $audit->sequence;
        }

        $this->assertSame([2, 3], $sequences);
    }

    public function test_it_walks_an_empty_stream(): void
    {
        $this->assertSame([], iterator_to_array($this->ledger()->stream('nonesuch')));
    }

    public function test_it_finds_what_it_wrote(): void
    {
        $ledger = $this->retaining();
        $audit = $ledger->write(auditData());
        $this->settle($ledger);

        $this->assertSame($audit->hash, $ledger->find($audit->id)?->hash);
    }

    public function test_it_gives_an_appended_entry_back_unchanged(): void
    {
        $ledger = $this->retaining();
        $sealed = $this->sealedElsewhere();
        $ledger->append($sealed);
        $this->settle($ledger);

        $this->assertSame($sealed->hash, $ledger->find($sealed->id)?->hash);
    }

    public function test_it_finds_nothing_for_an_unknown_id(): void
    {
        $this->assertNull($this->ledger()->find('01JXXXXXXXXXXXXXXXXXXXXXXX'));
    }

    abstract protected function ledger(): Ledger;

    /**
     * Whether find() and stream() give back what the ledger was handed. A driver that keeps
     * nothing answers false and the expectations that read an entry back are skipped — the
     * chain expectations still apply to it in full.
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
        return app(EntryBuilder::class)->build(auditData(), 'imported', 1, null, null);
    }

    private function retaining(): Ledger
    {
        if (! $this->retains()) {
            $this->markTestSkipped('This ledger keeps nothing, so there is nothing to read back.');
        }

        return $this->ledger();
    }
}
