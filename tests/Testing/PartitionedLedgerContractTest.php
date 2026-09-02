<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function ElPandaPe\Sentinel\Tests\divisionForThisEngine;
use function ElPandaPe\Sentinel\Tests\partitionTheTrail;

/**
 * The same contract the flat table answers, asked of the divided one. Same driver, same code, other
 * DDL — which is the claim v0.20.0 makes and the only way to hold it to it.
 */
final class PartitionedLedgerContractTest extends LedgerContractTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $division = divisionForThisEngine();

        if ($division === null) {
            $this->markTestSkipped('SQLite does not partition, so there is no divided table to hold the contract to.');
        }

        partitionTheTrail($division);
    }

    protected function ledger(): Ledger
    {
        return app(DatabaseLedger::class);
    }
}
