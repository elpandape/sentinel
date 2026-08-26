<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\TestCase;

use function ElPandaPe\Sentinel\Tests\auditData;

/**
 * The expectations every ledger has to meet. Chain expectations apply to all of them;
 * persistence expectations only to a ledger that stores what it writes.
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

    public function test_it_walks_a_stream_in_order(): void
    {
        $ledger = $this->ledger();
        $ledger->writeMany([auditData(), auditData(), auditData()]);

        $sequences = [];

        foreach ($ledger->stream('global') as $audit) {
            $sequences[] = $audit->sequence;
        }

        $this->assertSame([1, 2, 3], $sequences);
    }

    public function test_it_walks_a_bounded_range_of_a_stream(): void
    {
        $ledger = $this->ledger();
        $ledger->writeMany([auditData(), auditData(), auditData()]);

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
        $ledger = $this->ledger();
        $audit = $ledger->write(auditData());

        $this->assertSame($audit->hash, $ledger->find($audit->id)?->hash);
    }

    public function test_it_finds_nothing_for_an_unknown_id(): void
    {
        $this->assertNull($this->ledger()->find('01JXXXXXXXXXXXXXXXXXXXXXXX'));
    }

    public function test_it_stores_the_entry_it_returns(): void
    {
        if (! $this->persists()) {
            $this->markTestSkipped('This ledger does not persist: find() and stream() answer from memory.');
        }

        $audit = $this->ledger()->write(auditData());

        $this->assertSame(1, Audit::query()->where('id', $audit->id)->count());
    }

    public function test_it_refuses_two_entries_with_the_same_sequence_in_a_stream(): void
    {
        if (! $this->persists()) {
            $this->markTestSkipped('Uniqueness is enforced by the index, and this ledger has no table.');
        }

        $audit = $this->ledger()->write(auditData());

        $this->assertSame(1, Audit::query()->where('stream', $audit->stream)->count());
    }

    abstract protected function ledger(): Ledger;

    protected function persists(): bool
    {
        return true;
    }
}
