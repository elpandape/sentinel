<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('walks past a tombstone instead of stopping at it, and counts what it found', function (): void {
    foreach (range(1, 5) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    $walk = Sentinel::verifyEverything()->streams[0] ?? null;

    expect($walk?->chain->checked)->toBe(6)
        ->and($walk?->redacted())->toBe(1)
        ->and($walk?->content[ContentState::Sealed->value] ?? 0)->toBe(5)
        ->and($walk?->isIntact())->toBeTrue();
});

it('keeps the chain verifying through a tombstone', function (): void {
    foreach (range(1, 5) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 3)->firstOrFail(), 'erasure request');

    expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
});

it('reports the tampering of a stream that also holds a tombstone before it', function (): void {
    foreach (range(1, 9) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 4)->firstOrFail(), 'erasure request');

    $tampered = Audit::query()->where('sequence', 9)->firstOrFail();
    DB::table(auditsTable())->where('id', $tampered->id)->update(['before' => json_encode(['a' => 99])]);

    $result = Sentinel::verifyIntegrity('global');

    expect($result->isIntact())->toBeFalse()
        ->and($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(9);
});

it('counts the tombstones it walked past on the way to the tampering', function (): void {
    foreach (range(1, 9) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 4)->firstOrFail(), 'erasure request');
    redactor()->redact(Audit::query()->where('sequence', 5)->firstOrFail(), 'erasure request');

    $tampered = Audit::query()->where('sequence', 9)->firstOrFail();
    DB::table(auditsTable())->where('id', $tampered->id)->update(['before' => json_encode(['a' => 99])]);

    $walk = Sentinel::verifyEverything()->streams[0] ?? null;

    expect($walk?->redacted())->toBe(2)
        ->and($walk?->content[ContentState::Sealed->value] ?? 0)->toBe(6)
        ->and($walk?->content[ContentState::Altered->value] ?? 0)->toBe(1)
        ->and($walk?->isIntact())->toBeFalse();
});

it('still stops at a tampering that comes before a tombstone', function (): void {
    foreach (range(1, 9) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 8)->firstOrFail(), 'erasure request');

    $tampered = Audit::query()->where('sequence', 3)->firstOrFail();
    DB::table(auditsTable())->where('id', $tampered->id)->update(['before' => json_encode(['a' => 99])]);

    $result = Sentinel::verifyIntegrity('global');

    expect($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(3);
});

it('adds up the content states of every stream it walked', function (): void {
    foreach (range(1, 3) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    sentinelConfig(['integrity.stream' => 'tenant']);
    ledger()->write(auditData(['tenant_id' => 'acme', 'before' => ['b' => 2]]));

    redactor()->redact(Audit::query()->where('stream', 'global')->where('sequence', 1)->firstOrFail(), 'erasure request');
    redactor()->redact(Audit::query()->where('stream', 'tenant:acme')->where('sequence', 1)->firstOrFail(), 'erasure request');

    expect(Sentinel::verifyEverything()->content()[ContentState::Redacted->value] ?? 0)->toBe(2);
});

it('says how many entries it verified were redacted, and still exits zero', function (): void {
    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('were redacted on purpose')
        ->assertSuccessful();
});

it('counts the redacted entries beside the read ones in the table', function (): void {
    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    $this->artisan('sentinel:verify')->expectsOutputToContain('(1 redacted)')->assertSuccessful();
});
