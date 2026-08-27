<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class DatabaseLedgerContractTest extends LedgerContractTestCase
{
    use RefreshDatabase;

    protected function ledger(): Ledger
    {
        return app(DatabaseLedger::class);
    }
}
