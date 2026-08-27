<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\EncryptionException;
use ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData;
use ElPandaPe\Sentinel\Security\Keyring;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\encryptedEntry;
use function ElPandaPe\Sentinel\Tests\keyring;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([EncryptSensitiveData::class]);
});

it('replaces the value in the same key instead of wrapping it', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['name' => 'Ada', 'secret' => 'launch codes']])));

    expect(array_keys($audit?->after ?? []))->toBe(['name', 'secret'])
        ->and($audit?->after['secret'] ?? null)->toBeString()->not->toBe('launch codes');
});

it('records which fields it encrypted and with which key', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    expect($audit?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default']);
});

it('records nothing when the declared field is nowhere in the entry', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['name' => 'Ada']])));

    expect($audit?->encryption)->toBeNull();
});

it('leaves an entry that declares no encrypted field entirely alone', function (): void {
    $audit = pipeline()->process(auditData(['after' => ['secret' => 'launch codes']]));

    expect($audit?->after)->toBe(['secret' => 'launch codes'])
        ->and($audit?->encryption)->toBeNull();
});

it('gives the same value a different ciphertext every time', function (): void {
    $first = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'same']])));
    $second = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'same']])));

    expect($first?->after)->not->toBe($second?->after);
});

it('round trips the value, and its type, through the key that wrote it', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry([
        'after' => ['secret' => ['pin' => 1234, 'issued' => true]],
    ])));

    $ciphertext = $audit?->after['secret'] ?? '';

    expect(keyring()->for('default')->decrypt(is_string($ciphertext) ? $ciphertext : ''))
        ->toBe(['pin' => 1234, 'issued' => true]);
});

it('encrypts both sides of a change and every container it hides in', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry([
        'before' => ['secret' => 'first'],
        'after' => ['secret' => 'second'],
        'metadata' => ['echo' => ['secret' => 'third']],
        'context' => ['arguments' => ['secret' => 'fourth']],
        'changes' => [['path' => '/secret', 'op' => 'replace', 'old' => 'first', 'new' => 'second']],
    ])));

    $written = json_encode([$audit?->before, $audit?->after, $audit?->metadata, $audit?->context, $audit?->changes]);

    expect($written)->not->toContain('first')
        ->not->toContain('second')
        ->not->toContain('third')
        ->not->toContain('fourth');
});

it('leaves a null as the absence of a value rather than encrypting one', function (): void {
    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => null]])));

    expect($audit?->after)->toBe(['secret' => null])
        ->and($audit?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default']);
});

it('writes with the key the configuration names', function (): void {
    config()->set('sentinel.security.encryption.key_id', 'rotated');
    config()->set('sentinel.security.encryption.keys', ['rotated' => str_repeat('r', 32)]);

    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    expect($audit?->encryption['key_id'] ?? null)->toBe('rotated');
});

it('encrypts a field named only in configuration, on an entry no model owns', function (): void {
    config()->set('sentinel.security.encryption.fields', ['session_id']);

    $audit = pipeline()->process(auditData(['context' => ['session_id' => 'abc']]));

    expect($audit?->context['session_id'] ?? null)->toBeString()->not->toBe('abc')
        ->and($audit?->encryption)->toBe(['fields' => ['session_id'], 'key_id' => 'default']);
});

it('refuses to write with a key that is not on the keyring', function (): void {
    config()->set('sentinel.security.encryption.key_id', 'missing');

    pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));
})->throws(EncryptionException::class, 'no key [missing]');

it('says a key the cipher cannot use is unusable, and which cipher', function (): void {
    config()->set('sentinel.security.encryption.keys', ['default' => 'too short']);

    keyring()->for('default');
})->throws(EncryptionException::class, 'aes-256-gcm');

it('honours a declared cipher', function (): void {
    config()->set('sentinel.security.encryption.cipher', 'aes-256-cbc');
    config()->set('sentinel.security.encryption.keys', ['default' => str_repeat('k', 32)]);

    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    expect(keyring()->for('default')->decrypt((string) ($audit?->after['secret'] ?? '')))->toBe('launch codes');
});

it('reads a base64 key the way the framework writes one', function (): void {
    config()->set('sentinel.security.encryption.keys', ['default' => 'base64:'.base64_encode(str_repeat('b', 32))]);

    expect(keyring()->for('default'))->toBeInstanceOf(Illuminate\Contracts\Encryption\Encrypter::class);
});

it('builds one encrypter per key however many entries it writes', function (): void {
    $keyring = new Keyring(app(ElPandaPe\Sentinel\Support\Config::class));

    expect($keyring->for('default'))->toBe($keyring->for('default'));
});

it('refuses a keyring that is not a map', function (): void {
    config()->set('sentinel.security.encryption.keys', 'one-key');

    keyring()->for('default');
})->throws(ConfigurationException::class, 'security.encryption.keys');

it('refuses a key that is not a string', function (): void {
    config()->set('sentinel.security.encryption.keys', ['default' => 42]);

    keyring()->for('default');
})->throws(ConfigurationException::class, 'security.encryption.keys.default');

it('refuses a key identifier that is not a name', function (): void {
    config()->set('sentinel.security.encryption.key_id', 42);

    keyring()->current();
})->throws(ConfigurationException::class, 'security.encryption.key_id');

it('refuses a cipher that is not a name', function (): void {
    config()->set('sentinel.security.encryption.cipher', 256);

    keyring()->for('default');
})->throws(ConfigurationException::class, 'security.encryption.cipher');

it('falls back to the application key for the default identifier only', function (): void {
    config()->set('sentinel.security.encryption.keys');

    expect(keyring()->for('default'))->toBeInstanceOf(Illuminate\Contracts\Encryption\Encrypter::class);
});

it('says what to do when there is no default key and no application key either', function (): void {
    config()->set('sentinel.security.encryption.keys');
    config()->set('app.key');

    keyring()->for('default');
})->throws(ConfigurationException::class, 'key:generate');

it('falls back to the package cipher and key identifier when the published config names neither', function (): void {
    config()->set('sentinel.security.encryption.key_id');
    config()->set('sentinel.security.encryption.cipher');

    $audit = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    expect($audit?->encryption['key_id'] ?? null)->toBe('default')
        ->and(keyring()->for('default')->decrypt((string) ($audit?->after['secret'] ?? '')))->toBe('launch codes');
});

it('needs no key at all from an installation that encrypts nothing', function (): void {
    config()->set('sentinel.security.encryption.keys');
    config()->set('app.key');

    $data = auditData(['after' => ['secret' => 'launch codes']]);

    expect(pipeline()->process($data))->toBe($data);
});

it('names the encrypted fields in a stable order, because that list is part of the hash', function (): void {
    config()->set('sentinel.security.encryption.fields', ['zulu', 'alpha']);

    $audit = pipeline()->process(auditData(['after' => ['zulu' => 'one', 'alpha' => 'two']]));

    expect($audit?->encryption)->toBe(['fields' => ['alpha', 'zulu'], 'key_id' => 'default']);
});

it('refuses a base64 key whose payload is not base64', function (): void {
    config()->set('sentinel.security.encryption.keys', ['default' => 'base64:not valid base64!!']);

    keyring()->for('default');
})->throws(EncryptionException::class, 'aes-256-gcm');
