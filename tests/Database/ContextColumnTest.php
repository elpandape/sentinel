<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\contextEngine;
use function ElPandaPe\Sentinel\Tests\httpRequest;

beforeEach(function (): void {
    httpRequest('/api/invoices', ['User-Agent' => 'Sentinel/1.0']);

    $this->ledger = app(Ledger::class);
    $this->written = $this->ledger->write(contextEngine()(auditData()));
});

it('reads back the resolved context the way it wrote it', function (): void {
    $read = $this->ledger->find($this->written->id);

    expect($read?->context)->toBe($this->written->context)
        ->and($read?->source)->toBe($this->written->source)
        ->and($read?->request_id)->toBe($this->written->request_id);
});

it('verifies an entry whose context is populated', function (): void {
    expect($this->written->verifyIntegrity())->toBeTrue()
        ->and($this->written->payload_version)->toBe(1);
});
