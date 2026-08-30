<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Exceptions\SignatureException;
use ElPandaPe\Sentinel\Integrity\HmacSigner;
use ElPandaPe\Sentinel\Integrity\OpenSslSigner;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;

use function ElPandaPe\Sentinel\Tests\hashToSign;
use function ElPandaPe\Sentinel\Tests\signers;

it('either verifies what it signed or produces nothing to verify', function (Signer $signer, bool $signs): void {
    $signature = $signer->sign(hashToSign());

    expect($signature !== '')->toBe($signs)
        ->and($signer->verify(hashToSign(), $signature))->toBe($signs);
})->with(signers());

it('never verifies a signature over a different hash', function (Signer $signer): void {
    expect($signer->verify(hash('sha256', 'another entry'), $signer->sign(hashToSign())))->toBeFalse();
})->with(signers());

it('never verifies a signature someone altered', function (Signer $signer): void {
    $altered = 'A'.substr($signer->sign(hashToSign()), 1);

    expect($signer->verify(hashToSign(), $altered))->toBeFalse();
})->with(signers());

it('names the key it used', function (Signer $signer): void {
    expect($signer->keyId())->not->toBeEmpty();
})->with(signers());

it('does not verify what the key that came after it signed', function (Signer $before, Signer $after): void {
    expect($after->verify(hashToSign(), $before->sign(hashToSign())))->toBeFalse();
})->with([
    'hmac' => [
        new HmacSigner('v1', SigningKeys::SECRET, 'sha256'),
        new HmacSigner('v2', SigningKeys::ROTATED_SECRET, 'sha256'),
    ],
    'openssl' => [
        new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, SigningKeys::PRIVATE_KEY, 'sha256'),
        new OpenSslSigner('v2', SigningKeys::ROTATED_PUBLIC_KEY, null, 'sha256'),
    ],
]);

it('refuses to sign with a key it only holds the public half of', function (): void {
    expect(fn (): string => new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, null, 'sha256')->sign(hashToSign()))
        ->toThrow(SignatureException::class, 'writes nothing itself');
});

it('says which half of the key it could not read', function (): void {
    expect(fn (): string => new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, 'not a pem', 'sha256')->sign(hashToSign()))
        ->toThrow(SignatureException::class, 'private key for [v1]')
        ->and(fn (): bool => new OpenSslSigner('v1', 'not a pem', null, 'sha256')->verify(hashToSign(), 'x'))
        ->toThrow(SignatureException::class, 'public key for [v1]');
});

it('refuses an algorithm the configuration accepts and openssl does not know as a digest', function (): void {
    expect(fn (): string => new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, SigningKeys::PRIVATE_KEY, 'crc32b')->sign(hashToSign()))
        ->toThrow(SignatureException::class, 'does not know that digest');
});

it('refuses a key that signs the message itself and will not take one already hashed', function (): void {
    expect(fn (): string => new OpenSslSigner('v1', SigningKeys::EDWARDS_PUBLIC_KEY, SigningKeys::EDWARDS_PRIVATE_KEY, 'sha256')->sign(hashToSign()))
        ->toThrow(SignatureException::class, 'as EdDSA keys do');
});

it('refuses a signature that is not even base64', function (): void {
    expect(new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, null, 'sha256')->verify(hashToSign(), '!!!'))->toBeFalse();
});
