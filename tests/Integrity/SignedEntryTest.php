<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\signingWith;

it('leaves both columns null while signing is switched off', function (): void {
    $audit = ledger()->write(auditData());

    expect($audit->signature)->toBeNull()
        ->and($audit->signature_key_id)->toBeNull()
        ->and($audit->verifySignature())->toBe(SignatureState::Unsigned);
});

it('signs the hash it just wrote, and names the key it used', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    expect($audit->signature)->not->toBeNull()
        ->and($audit->signature_key_id)->toBe('v1')
        ->and($audit->verifySignature())->toBe(SignatureState::Signed);
});

it('signs the hash and not the payload', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    expect($audit->signature)->toBe(hash_hmac('sha256', $audit->hash, SigningKeys::SECRET));
});

it('keeps the signature out of what the hash covers', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    expect($audit->verifyIntegrity())->toBeTrue();
});

it('reports a signature someone rewrote as invalid', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature' => str_repeat('0', 64)]);

    expect(Audit::query()->findOrFail($audit->id)->verifySignature())->toBe(SignatureState::Invalid);
});

it('reports a key that left the ring as unresolvable, not as forged', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    config()->set('sentinel.integrity.signature.keys', ['v2' => SigningKeys::ROTATED_SECRET]);

    app()->forgetScopedInstances();

    expect(Audit::query()->findOrFail($audit->id)->verifySignature())->toBe(SignatureState::UnknownKey);
});

it('reports a signature stripped of the key that made it as unresolvable', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature_key_id' => null]);

    expect(Audit::query()->findOrFail($audit->id)->verifySignature())->toBe(SignatureState::UnknownKey);
});

it('keeps verifying what the key before the rotation signed', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $before = ledger()->write(auditData());

    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET, 'v2' => SigningKeys::ROTATED_SECRET]);
    config()->set('sentinel.integrity.signature.key_id', 'v2');

    $after = ledger()->write(auditData());

    expect($after->signature_key_id)->toBe('v2')
        ->and($after->verifySignature())->toBe(SignatureState::Signed)
        ->and(Audit::query()->findOrFail($before->id)->verifySignature())->toBe(SignatureState::Signed);
});

it('signs every entry of a batch', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $written = ledger()->writeMany([auditData(), auditData(), auditData()]);

    expect($written)->toHaveCount(3);

    foreach ($written as $audit) {
        expect($audit->verifySignature())->toBe(SignatureState::Signed);
    }
});

it('verifies a signed entry with encrypted fields while holding no encryption key', function (): void {
    config()->set('sentinel.security.encryption.fields', ['secret']);

    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData(['after' => ['secret' => 'the plaintext']]));

    // The auditor who verifies is not the operator who decrypts: no key is reachable from here on.
    config()->set('sentinel.security.encryption.keys', []);

    app()->forgetScopedInstances();

    $reread = Audit::query()->findOrFail($audit->id);

    expect($reread->verifyIntegrity())->toBeTrue()
        ->and($reread->verifySignature())->toBe(SignatureState::Signed);
});
