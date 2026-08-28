<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;
use ElPandaPe\Sentinel\Tests\Fixtures\NarrowLedger;

final class NarrowLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= new NarrowLedger(app(MemoryLedger::class));
    }
}
