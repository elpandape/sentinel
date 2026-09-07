<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\CanonicalizationException;
use ElPandaPe\Sentinel\Exceptions\EncryptionException;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Tests\Fixtures\SecretiveSubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\keyring;
use function ElPandaPe\Sentinel\Tests\signingWith;

it('carries the identifier of a key into an entry and never the key itself', function (): void {
    $encryption = str_repeat('e', 32);
    $signing = 'plaintext-signing-key-3d9c';
    $salt = 'plaintext-hash-salt-7a4e';

    config()->set('sentinel.security.encryption.key_id', 'writing');
    config()->set('sentinel.security.encryption.keys', ['writing' => $encryption]);
    config()->set('sentinel.security.hashing.salt', $salt);

    signingWith('signing', $signing);

    SecretiveSubject::query()->create([
        'name' => 'Ada',
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $written = json_encode(DB::table(auditsTable())->get()->all());

    expect($written)->toBeString()
        ->toContain('writing')
        ->toContain('signing')
        ->not->toContain($encryption)
        ->not->toContain($signing)
        ->not->toContain($salt);
});

it('names a key it cannot use by its identifier and never by its value', function (): void {
    $key = 'plaintext-key-far-too-short-for-the-cipher';

    config()->set('sentinel.security.encryption.keys', ['default' => $key]);

    $failure = rescue(
        static fn (): mixed => keyring()->for('default'),
        static fn (Throwable $thrown): Throwable => $thrown,
        report: false,
    );

    expect($failure)->toBeInstanceOf(EncryptionException::class)
        ->and($failure->getMessage())->toContain('[default]')
        ->and($failure->getMessage())->not->toContain($key);
});

it('refuses a string it cannot canonicalize without repeating the string', function (): void {
    $secret = "plaintext-not-utf8-6b2d\xB1\x31";

    $failure = rescue(
        static fn (): mixed => new JsonCanonicalizer()->canonicalize(['after' => ['secret' => $secret]]),
        static fn (Throwable $thrown): Throwable => $thrown,
        report: false,
    );

    expect($failure)->toBeInstanceOf(CanonicalizationException::class)
        ->and($failure->getMessage())->not->toContain('plaintext-not-utf8');
});
