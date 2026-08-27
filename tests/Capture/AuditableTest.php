<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\PolicySubject;

use function ElPandaPe\Sentinel\Tests\insertAudit;

it('answers for every method the contract declares, by using the trait alone', function (): void {
    expect(array_diff(get_class_methods(Auditable::class), get_class_methods(AuditedSubject::class)))
        ->toBeEmpty();
});

it('answers with no field policy when the model declares none', function (): void {
    $subject = new AuditedSubject;

    expect($subject->auditIncluded())->toBeEmpty()
        ->and($subject->auditExcluded())->toBeEmpty()
        ->and($subject->auditRedacted())->toBeEmpty()
        ->and($subject->auditEncrypted())->toBeEmpty()
        ->and($subject->auditHashed())->toBeEmpty()
        ->and($subject->auditSnapshotsEnabled())->toBeTrue()
        ->and($subject->auditSeverity())->toBeNull();
});

it('reads every field policy the model declares', function (): void {
    $subject = new PolicySubject;
    $subject->auditInclude = ['name'];
    $subject->auditExclude = ['secret'];
    $subject->auditRedact = ['email'];
    $subject->auditEncrypt = ['price'];
    $subject->auditHash = ['status'];
    $subject->auditSnapshots = false;

    expect($subject->auditIncluded())->toBe(['name'])
        ->and($subject->auditExcluded())->toBe(['secret'])
        ->and($subject->auditRedacted())->toBe(['email'])
        ->and($subject->auditEncrypted())->toBe(['price'])
        ->and($subject->auditHashed())->toBe(['status'])
        ->and($subject->auditSnapshotsEnabled())->toBeFalse();
});

it('takes a severity as the enum or as the value behind it', function (Severity|string $declared): void {
    $subject = new PolicySubject;
    $subject->auditSeverity = $declared;

    expect($subject->auditSeverity())->toBe(Severity::Warning);
})->with([Severity::Warning, 'warning']);

it('reaches the entries of its own subject, oldest first', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    insertAudit(['sequence' => 900, 'event' => 'created', 'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey()]);
    insertAudit(['sequence' => 901, 'event' => 'updated', 'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey()]);

    expect($subject->audits()->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($subject->latestAudit()?->event)->toBe('updated');
});

it('has no latest entry before anything happened', function (): void {
    expect(AuditedSubject::query()->create(['name' => 'Ada'])->latestAudit())->toBeNull();
});

it('refuses a field policy that is not a list', function (): void {
    $subject = new PolicySubject;
    $subject->auditExclude = 'secret';

    $subject->auditExcluded();
})->throws(ConfigurationException::class, 'auditExclude');

it('refuses a field policy holding something that is not a field name', function (): void {
    $subject = new PolicySubject;
    $subject->auditExclude = ['secret', 42];

    $subject->auditExcluded();
})->throws(ConfigurationException::class, 'auditExclude');

it('refuses a snapshot policy that is not a boolean', function (): void {
    $subject = new PolicySubject;
    $subject->auditSnapshots = 'yes';

    $subject->auditSnapshotsEnabled();
})->throws(ConfigurationException::class, 'auditSnapshots');

it('refuses a severity that is not one of the four', function (): void {
    $subject = new PolicySubject;
    $subject->auditSeverity = 'urgent';

    $subject->auditSeverity();
})->throws(ConfigurationException::class, 'urgent');

it('refuses a severity that is neither the enum nor its value', function (): void {
    $subject = new PolicySubject;
    $subject->auditSeverity = 3;

    $subject->auditSeverity();
})->throws(ConfigurationException::class, 'auditSeverity');
