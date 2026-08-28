<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\Label;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Post;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\lineOf;
use function ElPandaPe\Sentinel\Tests\linesOf;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus', 'Grace'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
    });
});

dataset('every many to many the package covers', [
    'belongsToMany' => ['guests'],
    'morphToMany' => ['labels'],
]);

it('writes an entry for an attach on every kind of relation', function (string $relation): void {
    $related = $relation === 'labels'
        ? Sentinel::withoutAuditing(static fn (): Label => Label::query()->create(['name' => 'urgent']))
        : $this->members[0];

    $this->team->{$relation}()->attach($related->getKey());

    $audit = auditsOf($this->team)->sole();

    expect($audit->audit_type)->toBe('relation')
        ->and($audit->event)->toBe(AuditEvent::Attached->value)
        ->and($audit->metadata)->toBe(['api' => 'attach'])
        ->and(linesOf($audit))->toHaveCount(1)
        ->and(lineOf($audit)['operation'])->toBe('attach')
        ->and(lineOf($audit)['relation'])->toBe($relation)
        ->and(lineOf($audit)['related_id'])->toBe((string) $related->getKey());
})->with('every many to many the package covers');

it('writes an entry for a detach on every kind of relation', function (string $relation): void {
    $related = $relation === 'labels'
        ? Sentinel::withoutAuditing(static fn (): Label => Label::query()->create(['name' => 'urgent']))
        : $this->members[0];

    $this->team->{$relation}()->attach($related->getKey());
    $this->team->{$relation}()->detach($related->getKey());

    $audit = auditsOf($this->team)->last();

    expect($audit->event)->toBe(AuditEvent::Detached->value)
        ->and($audit->metadata)->toBe(['api' => 'detach'])
        ->and(lineOf($audit)['operation'])->toBe('detach');
})->with('every many to many the package covers');

it('writes one entry for a sync, not one per record it touched', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    $this->team->members()->sync([$this->members[1]->getKey(), $this->members[2]->getKey()]);

    $audit = auditsOf($this->team)->last();

    expect(auditsOf($this->team))->toHaveCount(2)
        ->and($audit->event)->toBe(AuditEvent::Synced->value)
        ->and($audit->metadata)->toBe(['api' => 'sync'])
        ->and(linesOf($audit))->toHaveCount(3);
});

it('says of each record what happened to it, not which call was made', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());
    $this->team->members()->sync([$this->members[1]->getKey()]);

    $lines = linesOf(auditsOf($this->team)->last());

    expect(array_map(static fn (array $line): string => $line['related_id'].':'.$line['operation'], $lines))
        ->toEqualCanonicalizing([
            $this->members[0]->getKey().':detach',
            $this->members[1]->getKey().':attach',
        ]);
});

it('records the call that was made, in the metadata the chain covers', function (): void {
    $this->team->members()->syncWithoutDetaching([$this->members[0]->getKey()]);
    $this->team->members()->toggle([$this->members[1]->getKey()]);

    expect(auditsOf($this->team)->map(static fn (Audit $audit): mixed => $audit->metadata['api'])->all())
        ->toBe(['sync_without_detaching', 'toggle']);
});

it('writes nothing for the inner calls a sync is built from', function (): void {
    $this->team->members()->sync([$this->members[0]->getKey(), $this->members[1]->getKey()]);

    expect(auditsOf($this->team))->toHaveCount(1);
});

it('writes nothing for the inner calls a toggle is built from', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    $this->team->members()->toggle([$this->members[0]->getKey(), $this->members[1]->getKey()]);

    expect(auditsOf($this->team)->last()->metadata)->toBe(['api' => 'toggle'])
        ->and(auditsOf($this->team))->toHaveCount(2);
});

it('carries the pivot state on both sides of an update', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    $this->team->members()->updateExistingPivot($this->members[0]->getKey(), ['expires_at' => '2027-01-01']);

    $audit = auditsOf($this->team)->last();

    expect($audit->metadata)->toBe(['api' => 'update_existing_pivot'])
        ->and(lineOf($audit)['operation'])->toBe('update')
        ->and(lineOf($audit)['pivot_before'])->toBe(['expires_at' => '2026-01-01', 'role' => 'lead'])
        ->and(lineOf($audit)['pivot_after'])->toBe(['expires_at' => '2027-01-01', 'role' => 'lead']);
});

it('drops the two foreign keys from the pivot state, which the entry already names', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);

    expect(array_keys((array) lineOf(auditsOf($this->team)->sole())['pivot_after']))
        ->toBe(['expires_at', 'role']);
});

it('tells a pivot that never existed from one with no declared columns', function (): void {
    $this->team->guests()->attach($this->members[0]->getKey());

    $line = lineOf(auditsOf($this->team)->sole());

    expect($line['pivot_before'])->toBeNull()
        ->and($line['pivot_after'])->toBe([]);
});

it('names the subject the relation hangs off, not the record it reached', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    $audit = auditsOf($this->team)->sole();

    expect($audit->subject_type)->toBe(Team::class)
        ->and($audit->subject_id)->toBe((string) $this->team->getKey())
        ->and(lineOf($audit)['related_type'])->toBe(Member::class);
});

it('audits the inverse side of a polymorphic relation, with a type per relation', function (): void {
    [$label, $post] = Sentinel::withoutAuditing(static fn (): array => [
        Label::query()->create(['name' => 'urgent']),
        Post::query()->create(),
    ]);

    $label->teams()->attach($this->team->getKey());
    $label->posts()->attach($post->getKey());

    expect(auditsOf($label)->map(static fn (Audit $audit): mixed => lineOf($audit)['related_type'])->all())
        ->toBe([Team::class, Post::class])
        ->and(auditsOf($label)->map(static fn (Audit $audit): mixed => lineOf($audit)['relation'])->all())
        ->toBe(['teams', 'posts']);
});

it('writes no entry at all while auditing is paused', function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team->members()->attach($this->members[0]->getKey());
    });

    expect(auditsOf($this->team))->toBeEmpty()
        ->and($this->team->members()->count())->toBe(1);
});

it('leaves the return value of every operation alone', function (): void {
    expect($this->team->members()->sync([$this->members[0]->getKey()]))
        ->toHaveKeys(['attached', 'detached', 'updated'])
        ->and($this->team->members()->toggle([$this->members[1]->getKey()]))
        ->toHaveKeys(['attached', 'detached'])
        ->and($this->team->members()->updateExistingPivot($this->members[0]->getKey(), ['role' => 'lead']))
        ->toBe(1)
        ->and($this->team->members()->detach($this->members[0]->getKey()))
        ->toBe(1);
});

it('empties the relation when a detach names nobody', function (): void {
    $this->team->members()->attach([$this->members[0]->getKey(), $this->members[1]->getKey()]);

    $this->team->members()->detach();

    expect(linesOf(auditsOf($this->team)->last()))->toHaveCount(2)
        ->and($this->team->members()->count())->toBe(0);
});
