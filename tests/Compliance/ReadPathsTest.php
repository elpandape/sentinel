<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Compliance\AccessLog;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditAccess;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\seedSubjects;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\versioned;

beforeEach(function (): void {
    seedSubjects(7);

    foreach ([100, 150, 200] as $total) {
        ledger()->write(auditData(versioned($total)));
    }

    sentinelConfig(['compliance' => true]);
});

it('records a comparison as the read it performs', function (): void {
    Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 3);

    expect(AuditAccess::query()->count())->toBe(1)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(1);
});

it('records a lifeline as the read the query underneath performs', function (): void {
    Sentinel::transitions()->for(AuditedSubject::class, 7)->get();

    expect(AuditAccess::query()->count())->toBe(1)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(1);
});

it('records a life read out by subject, which reaches the trail through the query', function (): void {
    $this->artisan('sentinel:show', ['--subject' => AuditedSubject::class.':7'])->assertSuccessful();

    expect(AuditAccess::query()->count())->toBe(1);
});

it('leaves no record for an entry read out by its own identifier', function (): void {
    $id = Audit::query()->value('id');

    $this->artisan('sentinel:show', ['audit' => $id])->assertSuccessful();

    expect(AuditAccess::query()->count())->toBe(0)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(0);
});

it('leaves no record for the relation a model publishes', function (): void {
    $subject = AuditedSubject::query()->findOrFail(7);

    $subject->audits()->get();
    $subject->latestAudit();

    expect(AuditAccess::query()->count())->toBe(0)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(0);
});

it('leaves no record for a walk that reads to prove rather than to disclose', function (): void {
    Sentinel::verifyIntegrity('global');
    Sentinel::verifyAnchors('global');
    Sentinel::verifyRoots('global');
    Sentinel::verifyEverything();

    expect(AuditAccess::query()->count())->toBe(0)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(0);
});
