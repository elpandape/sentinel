<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\statementsAsking;
use function ElPandaPe\Sentinel\Tests\statementsWriting;

it('fits an entry in thirty-five columns, which is what the ceiling divides', function (): void {
    expect(app(DatabaseLedger::class)->write(auditData())->getAttributes())->toHaveCount(35);
});

it('divides a batch across statements at the narrowest engine ceiling', function (): void {
    expect(statementsWriting(936))->toBe(1);
});

it('opens a second statement one row past it', function (): void {
    expect(statementsWriting(937))->toBe(2);
});

it('asks about captures in as many statements as the same ceiling requires', function (): void {
    expect(statementsAsking(32_766))->toBe(1)
        ->and(statementsAsking(32_767))->toBe(2);
});
