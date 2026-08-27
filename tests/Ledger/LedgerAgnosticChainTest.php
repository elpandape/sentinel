<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    $this->ledger = app(MemoryLedger::class);
});

it('gives a frozen entry back reproducing the hash it was frozen with', function (array $attributes, string $canonical, string $hash): void {
    $frozen = new Audit()->forceFill([...$attributes, 'hash' => $hash]);

    $this->ledger->append($frozen);

    expect($this->ledger->find($frozen->id)?->hash)->toBe($hash)
        ->and(verifier($this->ledger)->verifyEntry($frozen))->toBeTrue();
})->with(GoldenLedger::entries());

it('verifies a chain through a ledger that never touched a database', function (): void {
    $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    $result = verifier($this->ledger)->verifyStream('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(3);
});

it('reports the break through a ledger with no database, the same as through one with a table', function (): void {
    $this->ledger->write(auditData());
    $tampered = $this->ledger->write(auditData());
    $tampered->forceFill(['event' => 'moved']);

    $result = verifier($this->ledger)->verifyStream('global');

    expect($result->isIntact())->toBeFalse()
        ->and($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(2);
});

it('continues a chain a ledger with a table started, through one without', function (): void {
    $written = app(DatabaseLedger::class)->writeMany([auditData(), auditData()]);

    foreach ($written as $audit) {
        $this->ledger->append($audit);
    }

    $result = verifier($this->ledger)->verifyStream('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(2);
});
