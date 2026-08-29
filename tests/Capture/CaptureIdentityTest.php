<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\rekeyer;

it('gives every captured entry an identifier of its own', function (): void {
    AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

    expect(Audit::query()->sole()->capture_id)->toBeString()->toHaveLength(26);
});

it('gives two captures two identifiers', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $subject->update(['name' => 'Grace']);

    $identifiers = auditsOf($subject)->pluck('capture_id')->all();

    expect($identifiers)->toHaveCount(2)
        ->and(array_unique($identifiers))->toHaveCount(2);
});

it('stamps an entry that describes no model too', function (): void {
    Sentinel::event('invoice.approved')->record();

    expect(Audit::query()->sole()->capture_id)->toBeString();
});

it('keeps an identifier the caller brought, so a retry stays the same unit of work', function (): void {
    $brought = frozenUlid('CAPTURE1');

    app(Recorder::class)->record(auditData(['capture_id' => $brought]));

    expect(Audit::query()->sole()->capture_id)->toBe($brought);
});

it('writes a rotation entry without one, because rotation never goes through a capture', function (): void {
    config()->set('sentinel.security.encryption.keys', [
        'default' => str_repeat('a', 32),
        'rotated' => str_repeat('b', 32),
    ]);

    $subject = EncryptedSubject::query()->create(['secret' => 'launch codes']);

    rekeyer()->rekey(auditsOf($subject)->firstOrFail(), 'rotated');

    expect(Audit::query()->where('event', 'rekeyed')->sole()->capture_id)->toBeNull();
});
