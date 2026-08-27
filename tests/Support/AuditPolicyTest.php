<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditableSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\IntKeySubject;
use ElPandaPe\Sentinel\Tests\Fixtures\PolicySubject;

it('reads the policy of a model that uses the trait', function (): void {
    $subject = new PolicySubject;
    $subject->auditExclude = ['secret'];
    $subject->auditSnapshots = false;
    $subject->auditSeverity = Severity::Critical;

    $policy = AuditPolicy::of($subject);

    expect($policy->excluded)->toBe(['secret'])
        ->and($policy->snapshots)->toBeFalse()
        ->and($policy->severity)->toBe(Severity::Critical);
});

it('reads the policy of a model that implements the contract without the trait', function (): void {
    $policy = AuditPolicy::of(new AuditableSubject);

    expect($policy->excluded)->toBe(['remember_token'])
        ->and($policy->snapshots)->toBeTrue()
        ->and($policy->severity)->toBeNull();
});

it('falls back to auditing everything for a model that says nothing', function (): void {
    $policy = AuditPolicy::of(new IntKeySubject);

    expect($policy->included)->toBeEmpty()
        ->and($policy->excluded)->toBeEmpty()
        ->and($policy->snapshots)->toBeTrue()
        ->and($policy->severity)->toBeNull();
});
