<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Security\Rekeyer;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\keyring;
use function ElPandaPe\Sentinel\Tests\rekeyer;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.security.encryption.keys', [
        'default' => str_repeat('a', 32),
        'rotated' => str_repeat('b', 32),
    ]);
});

it('leaves the original entry byte for byte where it was', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();
    $frozen = $original->getAttributes();

    rekeyer()->rekey($original, 'rotated');

    expect(Audit::query()->findOrFail($original->id)->getAttributes())->toBe($frozen);
});

it('writes a new entry that carries the value under the new key', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);

    $rekeyed = rekeyer()->rekey(auditsOf($subject)->firstOrFail(), 'rotated');

    expect($rekeyed?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'rotated'])
        ->and(keyring()->for('rotated')->decrypt((string) ($rekeyed?->after['secret'] ?? '')))->toBe('launch codes');
});

it('points the new entry back at the one it stands in for', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    $rekeyed = rekeyer()->rekey($original, 'rotated');

    expect($rekeyed?->source_audit_id)->toBe($original->id)
        ->and($rekeyed?->metadata['rekeyed'] ?? null)->toBe([
            'audit_id' => $original->id,
            'from' => 'default',
            'to' => 'rotated',
        ]);
});

it('records the rotation as an event of its own, on the same subject', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    $rekeyed = rekeyer()->rekey($original, 'rotated');

    expect($rekeyed?->audit_type)->toBe(Rekeyer::AUDIT_TYPE)
        ->and($rekeyed?->event)->toBe(AuditEvent::Rekeyed->value)
        ->and($rekeyed?->severity)->toBe(Severity::Notice)
        ->and($rekeyed?->subject_type)->toBe($original->subject_type)
        ->and($rekeyed?->subject_id)->toBe($original->subject_id);
});

it('links the new entry into the chain instead of interrupting it', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    $rekeyed = rekeyer()->rekey($original, 'rotated');

    expect($rekeyed?->sequence)->toBe($original->sequence + 1)
        ->and($rekeyed?->previous_hash)->toBe($original->hash)
        ->and(verifier()->verifyStream($original->stream)->isIntact())->toBeTrue();
});

it('keeps the original readable with the key that wrote it', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    rekeyer()->rekey($original, 'rotated');

    expect(keyring()->for('default')->decrypt((string) ($original->after['secret'] ?? '')))->toBe('launch codes');
});

it('re-encrypts every container the field was hiding in', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'first']);
    $subject->update(['secret' => 'second']);

    $rekeyed = rekeyer()->rekey(auditsOf($subject)->last() ?? new Audit, 'rotated');

    $rotated = keyring()->for('rotated');
    $change = $rekeyed?->changes[0] ?? [];

    expect($rotated->decrypt((string) ($rekeyed?->before['secret'] ?? '')))->toBe('first')
        ->and($rotated->decrypt((string) ($rekeyed?->after['secret'] ?? '')))->toBe('second')
        ->and($rotated->decrypt((string) ($change['old'] ?? '')))->toBe('first')
        ->and($rotated->decrypt((string) ($change['new'] ?? '')))->toBe('second');
});

it('rotates to the key the configuration names when none is given', function (): void {
    config()->set('sentinel.security.encryption.key_id', 'rotated');

    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();

    config()->set('sentinel.security.encryption.key_id', 'default');
    $original->encryption = ['fields' => ['secret'], 'key_id' => 'rotated'];

    expect(rekeyer()->rekey($original)?->encryption['key_id'] ?? null)->toBe('default');
});

it('does nothing for an entry that carries no encrypted field', function (): void {
    expect(rekeyer()->rekey(new Audit, 'rotated'))->toBeNull();
});

it('does nothing for an entry that names no key', function (): void {
    $audit = new Audit;
    $audit->encryption = ['fields' => ['secret']];

    expect(rekeyer()->rekey($audit, 'rotated'))->toBeNull();
});

it('does nothing for an entry already written with the target key', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);

    expect(rekeyer()->rekey(auditsOf($subject)->firstOrFail(), 'default'))->toBeNull();
});

it('leaves a value that is not a ciphertext exactly as it found it', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();
    $original->after = ['secret' => 42];

    expect(rekeyer()->rekey($original, 'rotated')?->after)->toBe(['secret' => 42]);
});

it('keeps the metadata the original carried alongside the note it adds', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();
    $original->metadata = ['reason' => 'quarterly rotation'];

    expect(rekeyer()->rekey($original, 'rotated')?->metadata)->toBe([
        'reason' => 'quarterly rotation',
        'rekeyed' => ['audit_id' => $original->id, 'from' => 'default', 'to' => 'rotated'],
    ]);
});

it('carries over only the field names, as a list', function (): void {
    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);
    $original = auditsOf($subject)->firstOrFail();
    $original->encryption = ['fields' => [42, 'secret'], 'key_id' => 'default'];

    expect(rekeyer()->rekey($original, 'rotated')?->encryption)->toBe([
        'fields' => ['secret'],
        'key_id' => 'rotated',
    ]);
});
