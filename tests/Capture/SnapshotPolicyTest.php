<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\SevereSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SnapshotlessSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\verifier;

it('writes an entry with no payload when the model turns snapshots off', function (): void {
    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $audits = auditsOf($subject);

    expect($audits->pluck('before')->all())->toBe([null, null])
        ->and($audits->pluck('after')->all())->toBe([null, null])
        ->and($audits->pluck('event')->all())->toBe(['created', 'updated']);
});

it('still chains and still verifies an entry with no payload', function (): void {
    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $audits = auditsOf($subject);

    expect($audits->last()?->previous_hash)->toBe($audits->first()?->hash)
        ->and($audits->last()?->verifyIntegrity())->toBeTrue()
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('lets the model grade its own entries above the configured default', function (): void {
    $subject = SevereSubject::query()->create(['name' => 'Ada']);
    $subject->delete();

    expect(auditsOf($subject)->pluck('severity')->all())
        ->toBe([Severity::Critical, Severity::Critical]);
});

it('falls back to the severity the configuration fixes for the event', function (): void {
    config()->set('sentinel.severity.events.updated', 'warning');

    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject)->pluck('severity')->all())
        ->toBe([Severity::Info, Severity::Warning]);
});

it('refuses a severity that is not one of the four', function (): void {
    config()->set('sentinel.severity.events.created', 'urgent');

    SnapshotlessSubject::query()->create(['name' => 'Ada']);
})->throws(ConfigurationException::class, 'urgent');
