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

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\projections;
use function ElPandaPe\Sentinel\Tests\redactor;
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
        ->and($result?->sequence)->toBe($audit->sequence)
        ->and($result?->checked)->toBe(2);
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

it('reads a pivot by what it says, not by the order an engine wrote it in', function (): void {
    $audit = auditsOf($this->team)->first();

    $stored = DB::table(auditRelationsTable())->where('audit_id', $audit->id)->value('pivot_after');

    $reordered = is_string($stored) ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : [];

    krsort($reordered);

    DB::table(auditRelationsTable())
        ->where('audit_id', $audit->id)
        ->update(['pivot_after' => json_encode($reordered, JSON_THROW_ON_ERROR)]);

    expect(projections()->verify('global'))->toBeNull();
});

it('reads a nested pivot the same way, however deep the disagreement is', function (): void {
    $audit = ledger()->write(auditData(['changes' => [[
        'relation' => 'members',
        'operation' => 'attach',
        'related_type' => Member::class,
        'related_id' => '1',
        'pivot_before' => null,
        'pivot_after' => ['grant' => ['scope' => 'all', 'by' => 'ada'], 'role' => 'lead'],
    ]]]));

    DB::table(auditRelationsTable())
        ->where('audit_id', $audit->id)
        ->update(['pivot_after' => json_encode(
            ['role' => 'lead', 'grant' => ['by' => 'ada', 'scope' => 'all']],
            JSON_THROW_ON_ERROR,
        )]);

    expect(projections()->verify('global'))->toBeNull();
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

    $result = $batched->verify('global');

    expect($result?->auditId)->toBe($audit->id)
        ->and($result?->checked)->toBe(2);
});

/**
 * The three columns of a line the report had no case for. It had one for the record a line points
 * at, and none for what the relation is called, what was done to it, or what type the record is —
 * so a projection could have disagreed with its entry on any of the three and answered that
 * everything agreed.
 */
it('reports a relation name the index disagrees on', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->update(['relation' => 'strangers']);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('reports an operation the index disagrees on', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->update(['operation' => 'detach']);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('reports a related type the index disagrees on', function (): void {
    $audit = auditsOf($this->team)->first();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->update(['related_type' => 'impostor']);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

/**
 * The table carries no key of its own, so a duplicated row is a divergence the count has to catch:
 * the entry lists the line once and the index holds it twice, and a comparison that only asked
 * whether each line appears would call that agreement.
 */
it('reports a row the index kept twice for a line the entry lists once', function (): void {
    $audit = auditsOf($this->team)->first();

    $row = DB::table(auditRelationsTable())->where('audit_id', $audit->id)->first();

    DB::table(auditRelationsTable())->insert((array) $row);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('keeps walking past a full batch that has nothing to report', function (): void {
    $batched = new Projections(
        app(Ledger::class),
        app(AuditRelation::class),
        app(RelationProjection::class),
        app(Dispatcher::class),
        batch: 2,
    );

    expect($batched->verify('global'))->toBeNull();
});

it('reads the pivot a line carried before the change as much as the one it carried after', function (): void {
    ledger()->write(auditData(['changes' => [[
        'relation' => 'members',
        'operation' => 'update',
        'related_type' => Member::class,
        'related_id' => '1',
        'pivot_before' => ['role' => 'member'],
        'pivot_after' => ['role' => 'lead'],
    ]]]));

    expect(projections()->verify('global'))->toBeNull();
});

it('reports a pivot-before somebody edited in the index and not in the entry', function (): void {
    $audit = ledger()->write(auditData(['changes' => [[
        'relation' => 'members',
        'operation' => 'update',
        'related_type' => Member::class,
        'related_id' => '1',
        'pivot_before' => ['role' => 'member'],
        'pivot_after' => ['role' => 'lead'],
    ]]]));

    DB::table(auditRelationsTable())
        ->where('audit_id', $audit->id)
        ->update(['pivot_before' => json_encode(['role' => 'owner'], JSON_THROW_ON_ERROR)]);

    expect(projections()->verify('global')?->reason)->toBe(IntegrityBreak::ProjectionMismatch);
});

it('compares the lines of one entry whatever order the index stored them in', function (): void {
    $audit = ledger()->write(auditData(['changes' => [
        ['relation' => 'members', 'operation' => 'attach', 'related_type' => Member::class, 'related_id' => '1'],
        ['relation' => 'members', 'operation' => 'attach', 'related_type' => Member::class, 'related_id' => '2'],
    ]]));

    $rows = DB::table(auditRelationsTable())->where('audit_id', $audit->id)->get()->all();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();
    DB::table(auditRelationsTable())->insert(array_map(
        static fn (object $row): array => (array) $row,
        array_reverse($rows),
    ));

    expect(projections()->verify('global'))->toBeNull();
});

it('keeps comparing the rest of a batch after skipping a redacted entry', function (): void {
    $audits = auditsOf($this->team);

    redactor()->redact($audits->first(), 'subject access request');

    DB::table(auditRelationsTable())->where('audit_id', $audits->last()->id)->delete();

    expect(projections()->verify('global')?->auditId)->toBe($audits->last()->id);
});
