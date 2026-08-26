<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

final class DatabaseLedgerContractTest extends LedgerContractTestCase
{
    protected function ledger(): Ledger
    {
        return app(DatabaseLedger::class);
    }
}
