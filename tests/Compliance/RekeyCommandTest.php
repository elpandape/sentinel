<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\RekeyCommand;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\keyring;

beforeEach(function (): void {
    config()->set('sentinel.security.encryption.keys', [
        'default' => str_repeat('a', 32),
        'rotated' => str_repeat('b', 32),
    ]);
});

it('re-encrypts what it finds and says how much of what it read', function (): void {
    EncryptedSubject::query()->create(['secret' => 'launch codes']);

    $this->artisan('sentinel:rekey', ['--key' => 'rotated'])
        ->expectsOutputToContain('Re-encrypted 1 of the 1 entries read')
        ->assertSuccessful();
});

it('leaves the original entry exactly where it was', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();
    $frozen = $original->getAttributes();

    $this->artisan('sentinel:rekey', ['--key' => 'rotated'])->assertSuccessful();

    expect(Audit::query()->findOrFail($original->id)->getAttributes())->toBe($frozen);
});

it('writes the new entry under the new key, pointing back at the original', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    $this->artisan('sentinel:rekey', ['--key' => 'rotated'])->assertSuccessful();

    $rekeyed = Audit::query()->where('source_audit_id', $original->id)->firstOrFail();

    expect($rekeyed->encryption['key_id'] ?? null)->toBe('rotated')
        ->and(keyring()->for('rotated')->decrypt((string) ($rekeyed->after['secret'] ?? '')))->toBe('launch codes');
});

it('says what it would do and writes nothing', function (): void {
    EncryptedSubject::query()->create(['secret' => 'launch codes']);

    $before = Audit::query()->count();

    $this->artisan('sentinel:rekey', ['--key' => 'rotated', '--dry-run' => true])
        ->expectsOutputToContain('Would re-encrypt')
        ->assertSuccessful();

    expect(Audit::query()->count())->toBe($before);
});

it('counts nothing when no entry carries a protected field', function (): void {
    $this->artisan('sentinel:rekey', ['--key' => 'rotated'])
        ->expectsOutputToContain('Re-encrypted 0 of the 0 entries read')
        ->assertSuccessful();
});

it('reports a key it cannot resolve instead of writing half a rotation', function (): void {
    EncryptedSubject::query()->create(['secret' => 'launch codes']);

    $this->artisan('sentinel:rekey', ['--key' => 'nonesuch'])
        ->expectsOutputToContain('Nothing was re-encrypted')
        ->assertExitCode(2);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(RekeyCommand::class)->getDescription())->toBe('Recifra un rango del rastro con otra clave');
});

it('narrows by tenant and by type from the command line', function (): void {
    EncryptedSubject::query()->create(['secret' => 'launch codes']);

    $this->artisan('sentinel:rekey', [
        '--key' => 'rotated',
        '--tenant' => 'nobody',
        '--type' => 'model',
        '--limit' => '10',
    ])->expectsOutputToContain('Re-encrypted 0 of the 0 entries read')->assertSuccessful();
});
