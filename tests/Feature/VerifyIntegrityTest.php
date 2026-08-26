<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;

beforeEach(function (): void {
    app(Ledger::class)->writeMany([auditData(), auditData(), auditData()]);
});

it('verifies a stream through the facade', function (): void {
    $result = Sentinel::verifyIntegrity('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(3);
});

it('verifies a range through the facade', function (): void {
    DB::table(auditsTable())->where('sequence', 1)->update(['event' => 'tampered']);

    expect(Sentinel::verifyIntegrity('global', 2)->isIntact())->toBeTrue()
        ->and(Sentinel::verifyIntegrity('global')->reason)->toBe(IntegrityBreak::HashMismatch);
});
