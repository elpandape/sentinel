<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Database\Factories\AuditFactory;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\CustomAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

use function ElPandaPe\Sentinel\Tests\auditsTable;

it('persists an entry without going through a ledger', function (): void {
    $audit = Audit::factory()->create();

    expect(DB::table(auditsTable())->where('id', $audit->id)->exists())->toBeTrue();
});

it('builds an entry that satisfies every not null column', function (): void {
    $audit = Audit::factory()->create();

    expect($audit->stream)->not->toBeEmpty()
        ->and($audit->hash)->toHaveLength(64)
        ->and($audit->payload_version)->toBe(1)
        ->and($audit->algorithm)->toBe('sha256');
});

it('builds many entries that keep the chain constraint', function (): void {
    Audit::factory()->count(5)->create();

    expect(DB::table(auditsTable())->count())->toBe(5);
});

it('writes none of the seven deferred columns', function (): void {
    expect(array_intersect(array_keys(new AuditFactory()->definition()), [
        'capture_id', 'source_audit_id', 'criteria', 'affected_rows',
        'redacted_at', 'redaction_reason', 'redacted_hash',
    ]))->toBeEmpty();
});

it('builds the package model when nothing overrides it', function (): void {
    expect(new AuditFactory()->modelName())->toBe(Audit::class);
});

it('builds the model the configuration names', function (): void {
    config()->set('sentinel.models.audit', CustomAudit::class);

    expect(new AuditFactory()->modelName())->toBe(CustomAudit::class);
});

it('ships with the package instead of being published into an application', function (): void {
    expect(ServiceProvider::publishableGroups())->not->toContain('sentinel-factories');
});
