<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\NullLedger;

final class NullLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(NullLedger::class);
    }

    protected function persists(): bool
    {
        return false;
    }
}
