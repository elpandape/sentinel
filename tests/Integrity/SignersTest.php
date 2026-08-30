<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\SignatureException;
use ElPandaPe\Sentinel\Integrity\HmacSigner;
use ElPandaPe\Sentinel\Integrity\NullSigner;
use ElPandaPe\Sentinel\Integrity\OpenSslSigner;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;

use function ElPandaPe\Sentinel\Tests\hashToSign;
use function ElPandaPe\Sentinel\Tests\signerRing;

beforeEach(function (): void {
    config()->set('sentinel.integrity.signature.enabled', true);
});

it('signs nothing while signing is switched off', function (): void {
    config()->set('sentinel.integrity.signature.enabled', false);

    expect(signerRing()->current())->toBeInstanceOf(NullSigner::class);
});

it('derives the default hmac secret from the application key when none is declared', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['default' => null]);

    $signature = signerRing()->current()->sign(hashToSign());

    $expected = hash_hmac('sha256', hashToSign(), hash_hmac('sha256', 'sentinel:signature', (string) config('app.key')));

    expect($signature)->toBe($expected);
});

it('does not derive a secret for a key that was named on purpose', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['default' => null]);

    expect(signerRing()->for('v2'))->toBeNull();
});

it('resolves the key an entry names, not the one that is current', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET, 'v2' => SigningKeys::ROTATED_SECRET]);
    config()->set('sentinel.integrity.signature.key_id', 'v2');

    $ring = signerRing();

    expect($ring->current()->keyId())->toBe('v2')
        ->and($ring->for('v1'))->toBeInstanceOf(HmacSigner::class)
        ->and($ring->for('v1')?->keyId())->toBe('v1');
});

it('keeps verifying what a retired key signed', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET]);
    config()->set('sentinel.integrity.signature.key_id', 'v1');

    $signature = signerRing()->current()->sign(hashToSign());

    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET, 'v2' => SigningKeys::ROTATED_SECRET]);
    config()->set('sentinel.integrity.signature.key_id', 'v2');

    expect(signerRing()->for('v1')?->verify(hashToSign(), $signature))->toBeTrue();
});

it('answers with nothing for a key that left the ring, rather than with a verdict', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['v2' => SigningKeys::ROTATED_SECRET]);

    expect(signerRing()->for('v1'))->toBeNull();
});

it('refuses to sign with a key the ring cannot resolve', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET]);
    config()->set('sentinel.integrity.signature.key_id', 'v9');

    expect(fn (): mixed => signerRing()->current())
        ->toThrow(SignatureException::class, 'not on [sentinel.integrity.signature.keys]');
});

it('hands the private half only to the key that is signing now', function (): void {
    config()->set('sentinel.integrity.signature.signer', 'openssl');
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::PUBLIC_KEY, 'v2' => SigningKeys::ROTATED_PUBLIC_KEY]);
    config()->set('sentinel.integrity.signature.key_id', 'v1');
    config()->set('sentinel.integrity.signature.private_key', SigningKeys::PRIVATE_KEY);

    $ring = signerRing();

    expect($ring->current())->toBeInstanceOf(OpenSslSigner::class)
        ->and($ring->current()->verify(hashToSign(), $ring->current()->sign(hashToSign())))->toBeTrue()
        ->and(fn (): mixed => $ring->for('v2')?->sign(hashToSign()))
        ->toThrow(SignatureException::class, 'writes nothing itself');
});

it('answers with nothing for an openssl key the ring does not name', function (): void {
    config()->set('sentinel.integrity.signature.signer', 'openssl');
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::PUBLIC_KEY]);

    expect(signerRing()->for('v2'))->toBeNull();
});

it('resolves the null signer only under its own identifier', function (): void {
    config()->set('sentinel.integrity.signature.signer', 'null');

    $ring = signerRing();

    expect($ring->for(NullSigner::KEY_ID))->toBeInstanceOf(NullSigner::class)
        ->and($ring->for('v1'))->toBeNull();
});

it('refuses a signer nobody ships', function (): void {
    config()->set('sentinel.integrity.signature.signer', 'smoke-signals');

    expect(fn (): mixed => signerRing()->for('default'))
        ->toThrow(ConfigurationException::class, 'Accepted: hmac, openssl, null.');
});

it('resolves each key once', function (): void {
    config()->set('sentinel.integrity.signature.keys', ['v1' => SigningKeys::SECRET]);

    $ring = signerRing();

    expect($ring->for('v1'))->toBe($ring->for('v1'));
});
