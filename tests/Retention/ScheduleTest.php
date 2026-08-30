<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Retention\Schedule;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Database\Eloquent\Relations\Relation;

use function ElPandaPe\Sentinel\Tests\retentionSchedule;

afterEach(fn () => Relation::morphMap([], false));

it('holds nothing when no policy is declared', function (): void {
    expect(retentionSchedule([])->isEmpty())->toBeTrue();
});

it('reads a subject policy through the morph map the application declared', function (): void {
    Relation::morphMap(['user' => ActingUser::class]);

    expect(retentionSchedule(['model:'.ActingUser::class => '7 years'])->subjectTargets())->toBe(['user']);
});

it('reads a subject policy written as a bare class name', function (): void {
    expect(retentionSchedule([AuditedSubject::class => '7 years'])->subjectTargets())
        ->toBe([AuditedSubject::class]);
});

it('reads a class written with a leading backslash as the same subject', function (): void {
    expect(retentionSchedule(['\\'.AuditedSubject::class => '7 years'])->subjectTargets())
        ->toBe([AuditedSubject::class]);
});

it('reads the kind of entry called model as a type and not as a subject', function (): void {
    $schedule = retentionSchedule(['model' => '90 days']);

    expect($schedule->subjectTargets())->toBeEmpty()
        ->and($schedule->typeTargets())->toBe(['model']);
});

it('lets the subject a policy names win over the kind of entry it is', function (): void {
    $schedule = retentionSchedule([AuditedSubject::class => '7 years', 'model' => '90 days']);

    $audit = new Audit()->forceFill(['subject_type' => AuditedSubject::class, 'audit_type' => 'model']);

    expect($schedule->covering($audit)?->duration->declared)->toBe('7 years');
});

it('governs an entry by its kind when no policy names its subject', function (): void {
    $schedule = retentionSchedule([AuditedSubject::class => '7 years', 'auth' => '90 days']);

    $audit = new Audit()->forceFill(['subject_type' => null, 'audit_type' => 'auth']);

    expect($schedule->covering($audit)?->duration->declared)->toBe('90 days');
});

it('keeps forever an entry that no policy names', function (): void {
    $schedule = retentionSchedule(['auth' => '90 days']);

    $audit = new Audit()->forceFill(['subject_type' => null, 'audit_type' => 'custom']);

    expect($schedule->covering($audit))->toBeNull();
});

it('accepts a policy for a kind of entry nothing writes yet', function (): void {
    expect(retentionSchedule(['access' => '1 year'])->typeTargets())->toBe(['access']);
});

it('refuses two keys that would govern the same entries', function (): void {
    Relation::morphMap(['user' => ActingUser::class]);

    expect(fn (): Schedule => retentionSchedule(['model:'.ActingUser::class => '7 years', 'model:user' => '90 days']))
        ->toThrow(ConfigurationException::class, 'both govern [user]');
});

it('names the key whose period it could not read', function (): void {
    expect(fn (): Schedule => retentionSchedule(['auth' => 'whenever']))
        ->toThrow(ConfigurationException::class, 'sentinel.retention.auth');
});
