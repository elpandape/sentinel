<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Compliance\AccessLog;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditAccess;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('records nothing about a read when compliance mode is off', function (): void {
    ledger()->write(auditData());

    Sentinel::audits()->get();

    expect(AuditAccess::query()->count())->toBe(0)
        ->and(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(0);
});

it('leaves an entry and a row for one read in compliance mode', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->get();

    expect(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(1)
        ->and(AuditAccess::query()->count())->toBe(1);
});

it('chains the entry that proves a read like any other', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->get();

    $entry = Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->firstOrFail();

    expect($entry->verifyIntegrity())->toBeTrue()
        ->and($entry->sequence)->toBe(2)
        ->and($entry->previous_hash)->not->toBeNull()
        ->and(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
});

it('points the row at the entry that makes it provable', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->get();

    $row = AuditAccess::query()->firstOrFail();
    $entry = Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->firstOrFail();

    expect($row->audit_id)->toBe($entry->id)
        ->and($row->audit()?->id)->toBe($entry->id)
        ->and($row->results)->toBe(1);
});

it('records the shape of the question rather than a rendered query', function (): void {
    ledger()->write(auditData(['tenant_id' => 'acme', 'event' => 'updated']));

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->forTenant('acme')->whereEvent('updated')->take(5)->get();

    $row = AuditAccess::query()->firstOrFail();

    expect($row->query['tenant_id'] ?? null)->toBe('acme')
        ->and($row->query['event'] ?? null)->toBe('updated')
        ->and($row->query['limit'] ?? null)->toBe(5);
});

it('does not audit its own writing, so one read is one entry', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->get();
    Sentinel::audits()->get();

    expect(Audit::query()->where('audit_type', AccessLog::AUDIT_TYPE)->count())->toBe(2)
        ->and(AuditAccess::query()->count())->toBe(2);
});

it('keeps the chain verifying with access entries inside it', function (): void {
    foreach (range(1, 3) as $ignored) {
        ledger()->write(auditData());
    }

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()->get();
    Sentinel::audits()->get();

    expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue()
        ->and(Audit::query()->count())->toBe(5);
});

it('records the subject and the actor a query narrowed to', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    Sentinel::audits()
        ->for('member', '9')
        ->by('operator', '3')
        ->get();

    $row = AuditAccess::query()->firstOrFail();

    expect($row->query['subject'] ?? null)->toBe('member:9')
        ->and($row->query['actor'] ?? null)->toBe('operator:3');
});

it('projects a read that happened inside a transaction, once it settles', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    DB::transaction(function (): void {
        Sentinel::audits()->get();
    });

    expect(AuditAccess::query()->count())->toBe(1)
        ->and(AuditAccess::query()->firstOrFail()->audit())->not->toBeNull();
});
