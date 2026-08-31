<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\ArchiveLedger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;
use Illuminate\Support\Facades\Storage;

final class ArchiveLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('cold');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sentinel.ledger.ledgers.archive.disk', 'cold');
    }

    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(ArchiveLedger::class);
    }

    /**
     * The contract publishes this hook for a driver whose reads do not see a write that just
     * returned, and this is one: an entry is held in an open batch until it fills or until somebody
     * asks. Sealing here is honouring the contract rather than working around it.
     */
    protected function settle(Ledger $ledger): void
    {
        if ($ledger instanceof ArchiveLedger) {
            $ledger->seal();
        }
    }
}
