<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Integrity\Projections;
use ElPandaPe\Sentinel\Ledger\RelationProjection;
use ElPandaPe\Sentinel\Models\AuditRelation;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\projections;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
    });

    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);
    $this->team->members()->attach($this->members[1]->getKey(), ['role' => 'member']);
});

it('finds nothing to report while the table says what the entries say', function (): void {
    expect(projections()->verify('global'))->toBeNull();
});

it('reports a line whose row somebody deleted', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();

    $result = projections()->verify('global');

    expect($result?->reason)->toBe(IntegrityBreak::ProjectionMismatch)
        ->and($result?->auditId)->toBe($audit->id)
        ->and($result?->sequence)->toBe($audit->sequence);
});

it('reports a row that belongs to no line at all', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->insert([
        'audit_id' => $audit->id,
        'relation' => 'members',
        'operation' => 'attach',
        'related_type' => Member::class,
        'related_id' => '999',
        'pivot_before' => null,
        'pivot_after' => null,
    ]);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('reports a pivot somebody edited in the index and not in the entry', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())
        ->where('audit_id', $audit->id)
        ->update(['pivot_after' => json_encode(['role' => 'owner'], JSON_THROW_ON_ERROR)]);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('reports a related record the index points somewhere else', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->update(['related_id' => '404']);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('leaves the chain intact while saying the projection is not', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();

    expect(verifier()->verifyStream('global')->isIntact())->toBeTrue()
        ->and(projections()->verify('global'))->not->toBeNull();
});

it('announces the divergence the way it announces a broken link', function (): void {
    Event::fake([IntegrityVerificationFailed::class]);

    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();

    projections()->verify('global');

    Event::assertDispatched(
        IntegrityVerificationFailed::class,
        static fn (IntegrityVerificationFailed $event): bool => $event->reason === IntegrityBreak::ProjectionMismatch
            && $event->auditId === $audit->id
            && str_contains($event->message(), 'The chain is intact'),
    );
});

it('asks nothing of the entries a range leaves out', function (): void {
    $audits = auditsOf($this->team);

    DB::table(auditRelationsTable())->where('audit_id', $audits->first()->id)->delete();

    expect(projections()->verify('global', from: $audits->last()->sequence))->toBeNull();
});

it('reports a row planted against an entry that never touched a relation', function (): void {
    $this->team->update(['name' => 'Platform']);

    $plain = auditsOf($this->team)->last();

    DB::table(auditRelationsTable())->insert([
        'audit_id' => $plain->id,
        'relation' => 'members',
        'operation' => 'attach',
        'related_type' => Member::class,
        'related_id' => '1',
        'pivot_before' => null,
        'pivot_after' => null,
    ]);

    expect(projections()->verify('global')?->auditId)->toBe($plain->id);
});

it('stops at the first batch that disagrees instead of reading the rest', function (): void {
    $this->team->members()->detach($this->members[0]->getKey());

    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();

    $batched = new Projections(
        app(Ledger::class),
        app(AuditRelation::class),
        app(RelationProjection::class),
        app(Dispatcher::class),
        batch: 2,
    );

    expect($batched->verify('global')?->auditId)->toBe($audit->id);
});
