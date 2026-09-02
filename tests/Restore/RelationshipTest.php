<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Events\AuditRestoring;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Restore\Restorer;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\reread;
use function ElPandaPe\Sentinel\Tests\restorableEntry;

it('attaches back what the entry attached and detaches what it detached', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);
    $grace = Member::query()->create(['name' => 'Grace']);

    $team->members()->attach($ada->getKey());
    $entry = auditsOf($team)->last();

    $team->members()->detach($ada->getKey());
    $team->members()->attach($grace->getKey());

    $result = $entry->restoreRelationship('members');

    expect($team->members()->orderBy('fixture_members.id')->pluck('fixture_members.id')->all())
        ->toBe([$ada->getKey(), $grace->getKey()])
        ->and($result->applied)->toBe(['members/'.$ada->getKey()]);
});

it('puts a pivot column back to the value the entry left on it', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey(), ['role' => 'lead']);
    $entry = auditsOf($team)->last();

    $team->members()->updateExistingPivot($ada->getKey(), ['role' => 'guest']);

    $entry->restoreRelationship('members');

    expect($team->members()->firstOrFail()->getAttribute('pivot')->getAttribute('role'))->toBe('lead');
});

it('takes a detached record back out of the relation', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey(), ['role' => 'lead']);
    $team->members()->detach($ada->getKey());
    $entry = auditsOf($team)->last();

    $team->members()->attach($ada->getKey(), ['role' => 'guest']);

    $entry->restoreRelationship('members');

    expect($team->members()->count())->toBe(0);
});

it('writes one entry for the whole relation, carrying its lines', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);
    $grace = Member::query()->create(['name' => 'Grace']);

    $team->members()->sync([$ada->getKey() => ['role' => 'lead'], $grace->getKey() => ['role' => 'guest']]);
    $entry = auditsOf($team)->last();

    $team->members()->detach();

    $result = $entry->restoreRelationship('members');
    $written = auditsOf($team)->last();

    expect($written->audit_type)->toBe(Restorer::AUDIT_TYPE)
        ->and($written->source_audit_id)->toBe($entry->id)
        ->and($written->relations)->toHaveCount(2)
        ->and($written->id)->toBe($result->entry?->id)
        ->and($written->diff())->toHaveCount(2)
        ->and(Sentinel::verifyIntegrity($written->stream)->isIntact())->toBeTrue();
});

it('skips a related record that is no longer there', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());
    $entry = auditsOf($team)->last();

    $team->members()->detach($ada->getKey());
    $ada->delete();

    $result = $entry->restoreRelationship('members');

    expect($result->reason('members/'.$ada->getKey()))->toBe(Omission::RelatedMissing)
        ->and($result->applied)->toBeEmpty()
        ->and($result->entry)->toBeNull();
});

it('writes nothing when the relation already looks the way the entry left it', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey(), ['role' => 'lead']);
    $entry = auditsOf($team)->last();

    $result = $entry->restoreRelationship('members');

    expect($result->applied)->toBeEmpty()
        ->and($result->reason('members/'.$ada->getKey()))->toBe(Omission::Unchanged)
        ->and(auditsOf($team))->toHaveCount(2);
});

it('skips every line when the model no longer declares that relation', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());

    $result = auditsOf($team)->last()->restoreRelationship('alumni');

    expect($result->refused)->toBe(Omission::EntryStateless);
});

it('refuses an entry that records nothing about that relation', function (): void {
    $team = Team::query()->create(['name' => 'core']);

    expect(restorableEntry($team, ['name' => 'core'])->restoreRelationship('members')->refused)
        ->toBe(Omission::EntryStateless);
});

it('refuses to reattach from an entry that no longer reproduces its own hash', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());
    $entry = auditsOf($team)->last();

    DB::table(auditsTable())->where('id', $entry->id)->update(['event' => 'invented']);

    expect(reread($entry)->restoreRelationship('members')->refused)
        ->toBe(Omission::EntryTampered);
});

it('refuses a relation restoration when the record it is about is gone', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $entry = restorableEntry($record, ['name' => 'Ada']);
    $record->forceDelete();

    expect($entry->restoreRelationship('members')->refused)->toBe(Omission::SubjectMissing);
});

it('leaves a relation alone when a listener cancels the restoration', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());
    $entry = auditsOf($team)->last();
    $team->members()->detach($ada->getKey());

    app(Dispatcher::class)->listen(AuditRestoring::class, static fn (): bool => false);

    expect($entry->restoreRelationship('members')->refused)->toBe(Omission::Cancelled)
        ->and($team->members()->count())->toBe(0);
});

it('has nothing to do when what the entry detached is already gone', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());
    $team->members()->detach($ada->getKey());

    $result = auditsOf($team)->last()->restoreRelationship('members');

    expect($result->applied)->toBeEmpty()
        ->and($result->reason('members/'.$ada->getKey()))->toBe(Omission::Unchanged);
});

it('refuses to reattach from a redacted entry', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    $ada = Member::query()->create(['name' => 'Ada']);

    $team->members()->attach($ada->getKey());
    $entry = auditsOf($team)->last();

    redactor()->redact($entry, 'erasure request');

    expect(reread($entry)->restoreRelationship('members')->refused)->toBe(Omission::EntryRedacted);
});

it('passes over a line that names no related record', function (): void {
    $team = Team::query()->create(['name' => 'core']);

    $entry = restorableEntry($team, [], ['changes' => [
        ['relation' => 'members', 'operation' => 'detach', 'related_id' => null, 'pivot_before' => null, 'pivot_after' => null],
    ]]);

    expect($entry->restoreRelationship('members')->refused)->toBe(Omission::EntryStateless);
});
