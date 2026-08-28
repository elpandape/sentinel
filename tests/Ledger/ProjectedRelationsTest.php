<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\ledger;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
    });
});

it('writes a row per line of the entry it sealed', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);
    $this->team->members()->sync([$this->members[1]->getKey()]);

    $audit = auditsOf($this->team)->last();

    expect(DB::table(auditRelationsTable())->where('audit_id', $audit->id)->count())->toBe(2);
});

it('projects exactly what the entry carries, field for field', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    $audit = auditsOf($this->team)->sole();
    $line = $audit->relations->sole();

    expect($line->relation)->toBe('members')
        ->and($line->operation)->toBe('attach')
        ->and($line->related_type)->toBe(Member::class)
        ->and($line->related_id)->toBe((string) $this->members[0]->getKey())
        ->and($line->pivot_before)->toBeNull()
        ->and($line->pivot_after)->toEqualCanonicalizing(['expires_at' => '2026-01-01', 'role' => 'lead']);
});

it('writes no row for an entry that is not about a relation', function (): void {
    Team::query()->create(['name' => 'Infra']);

    expect(DB::table(auditRelationsTable())->count())->toBe(0);
});

/**
 * The decision the whole split rests on: the lines are inside the canonical payload, so the chain
 * covers the fact. The table is an index over that fact, and rebuilding an index has to be
 * something other than tampering — otherwise nobody could ever rebuild one.
 */
it('still verifies after a projected row is taken away, because the table is not the evidence', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    $audit = auditsOf($this->team)->sole();
    DB::table(auditRelationsTable())->delete();

    expect($audit->relations()->count())->toBe(0)
        ->and($audit->verifyIntegrity())->toBeTrue();
});

it('stops verifying the moment a line inside the entry is altered', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    $audit = auditsOf($this->team)->sole();
    $lines = $audit->getAttribute('changes');
    $lines[0]['related_id'] = '999';

    DB::table('sentinel_audits')
        ->where('id', $audit->id)
        ->update(['changes' => json_encode($lines, JSON_THROW_ON_ERROR)]);

    expect(Audit::query()->findOrFail($audit->id)->verifyIntegrity())->toBeFalse();
});

it('projects the lines of an entry a secondary destination was handed', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);

    $sealed = auditsOf($this->team)->sole();
    DB::table(auditRelationsTable())->truncate();
    DB::table('sentinel_audits')->delete();

    ledger()->append($sealed);

    expect(DB::table(auditRelationsTable())->where('audit_id', $sealed->id)->count())->toBe(1)
        ->and(Audit::query()->findOrFail($sealed->id)->relations->sole())
        ->operation->toBe('attach');
});

it('leaves an appended entry that is not about a relation without lines', function (): void {
    $sealed = Team::query()->create(['name' => 'Infra'])->latestAudit();
    DB::table('sentinel_audits')->delete();

    ledger()->append($sealed);

    expect(DB::table(auditRelationsTable())->count())->toBe(0);
});

it('keeps a line that says nothing about a pivot as a null, not as an empty string', function (): void {
    $this->team->guests()->attach($this->members[0]->getKey());

    $line = auditsOf($this->team)->sole()->relations->sole();

    expect($line->pivot_before)->toBeNull()
        ->and($line->pivot_after)->toBe([]);
});

it('gives the lines back in the order the entry canonicalised them', function (): void {
    $this->team->members()->sync([$this->members[1]->getKey(), $this->members[0]->getKey()]);

    $ids = auditsOf($this->team)->sole()->relations
        ->map(static fn (AuditRelation $line): ?string => $line->related_id)
        ->all();

    expect($ids)->toBe([
        (string) $this->members[0]->getKey(),
        (string) $this->members[1]->getKey(),
    ]);
});
