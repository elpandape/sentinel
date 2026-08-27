<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Testing;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;
use Illuminate\Contracts\Events\Dispatcher;

final class FanoutLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= new FanoutLedger(
            app(MemoryLedger::class),
            [app(NullLedger::class)],
            FanoutPolicy::Strict,
            app(Dispatcher::class),
        );
    }
}
