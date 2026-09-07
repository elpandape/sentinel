<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsOf;
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

/**
 * The reason to hash over ciphertext, stated as the test it is owed: an auditor who cannot decrypt
 * can still prove the row was not touched.
 *
 * It captures through a model rather than writing to the ledger, and that is the whole point.
 * Encryption happens in the pipeline stage, and the ledger is downstream of it — a write straight to
 * the ledger copies whatever `encryption` the caller handed over and encrypts nothing, so an entry
 * built that way is signed plaintext wearing the name of this test. The first expectation exists to
 * make that failure loud rather than green: it asserts the entry really is encrypted before the
 * second one asserts it verifies without the key.
 */
it('verifies a signed entry with encrypted fields while holding no encryption key', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $subject = EncryptedSubject::query()->create(['secret' => 'the plaintext']);
    $audit = auditsOf($subject)->firstOrFail();

    expect($audit->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default'])
        ->and($audit->after['secret'] ?? null)->not->toBe('the plaintext');

    // The auditor who verifies is not the operator who decrypts: no key is reachable from here on.
    config()->set('sentinel.security.encryption.keys', []);

    app()->forgetScopedInstances();

    $reread = Audit::query()->findOrFail($audit->id);

    expect($reread->verifyIntegrity())->toBeTrue()
        ->and($reread->verifySignature())->toBe(SignatureState::Signed);
});

/**
 * The other half of "reports a signature stripped of the key that made it as unresolvable": an entry
 * that recorded no key identifier is asked about under the empty one, and an installation that
 * configures a key there gets a real verdict rather than a refusal. Unresolvable and invalid are the
 * two answers the report keeps apart, and which one an entry gets cannot depend on the fallback
 * happening to name an identifier nobody configured.
 */
it('resolves an entry that named no key against the empty identifier', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    config()->set('sentinel.integrity.signature.keys', [
        'v1' => SigningKeys::SECRET,
        '' => SigningKeys::ROTATED_SECRET,
    ]);

    app()->forgetScopedInstances();

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature_key_id' => null]);

    expect(Audit::query()->findOrFail($audit->id)->verifySignature())->toBe(SignatureState::Invalid);
});
