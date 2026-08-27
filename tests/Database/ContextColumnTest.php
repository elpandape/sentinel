<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\contextEngine;
use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\withSortedKeys;

beforeEach(function (): void {
    httpRequest('/api/invoices', ['User-Agent' => 'Sentinel/1.0']);

    $this->ledger = app(Ledger::class);
    $this->written = $this->ledger->write(contextEngine()(auditData()));
});

it('reads back every value of the resolved context, whatever the engine does to the key order', function (): void {
    $read = $this->ledger->find($this->written->id);

    expect(withSortedKeys($read?->context ?? []))->toBe(withSortedKeys($this->written->context))
        ->and($read?->source)->toBe($this->written->source)
        ->and($read?->request_id)->toBe($this->written->request_id);
});

it('verifies an entry whose context is populated', function (): void {
    expect($this->written->verifyIntegrity())->toBeTrue()
        ->and($this->written->payload_version)->toBe(1);
});
