<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedLedger;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    // The auditor who verifies is not the operator who decrypts: no key is reachable here.
    config()->set('sentinel.security.encryption.keys', []);
});

it('still canonicalizes a protected entry the way payload version one did', function (array $attributes, string $canonical): void {
    $audit = new Audit()->forceFill($attributes);

    expect(new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($audit)))->toBe($canonical);
})->with(EncryptedLedger::entries());

it('still reproduces the frozen hash of a protected entry', function (array $attributes, string $canonical, string $hash): void {
    expect(hasher()->hash(new Audit()->forceFill($attributes)))->toBe($hash);
})->with(EncryptedLedger::entries());

it('froze the entry at payload version one, because protecting a value changes no format', function (array $attributes): void {
    expect($attributes['payload_version'])->toBe(EntryBuilder::PAYLOAD_VERSION);
})->with(EncryptedLedger::entries());

it('carries no plaintext of the field it encrypted', function (array $attributes, string $canonical): void {
    expect($canonical)->not->toContain(EncryptedLedger::PLAINTEXT);
})->with(EncryptedLedger::entries());

it('verifies a chain of encrypted entries with an empty keyring', function (array $attributes): void {
    insertAudit(auditRow([
        'id' => '01JGOLDEN000000000000000A1',
        'stream' => 'tenant:acme',
        'sequence' => 6,
        'hash' => $attributes['previous_hash'],
    ]));

    $audit = new Audit()->forceFill([...$attributes, 'created_at' => '2026-08-27 12:00:00.000000']);
    $audit->hash = hasher()->hash($audit);

    DB::table(auditsTable())->insert($audit->getAttributes());

    expect(verifier()->verifyStream('tenant:acme', from: 7)->isIntact())->toBeTrue();
})->with(EncryptedLedger::entries());

it('breaks the chain when the key identifier of a stored row is altered', function (array $attributes, string $canonical, string $hash): void {
    $forged = new Audit()->forceFill([
        ...$attributes,
        'encryption' => ['fields' => ['dni'], 'key_id' => 'attacker'],
    ]);

    expect(hasher()->hash($forged))->not->toBe($hash);
})->with(EncryptedLedger::entries());

it('breaks the chain when a ciphertext of a stored row is swapped', function (array $attributes, string $canonical, string $hash): void {
    $after = is_array($attributes['after']) ? $attributes['after'] : [];

    $forged = new Audit()->forceFill([...$attributes, 'after' => [...$after, 'dni' => 'swapped']]);

    expect(hasher()->hash($forged))->not->toBe($hash);
})->with(EncryptedLedger::entries());
