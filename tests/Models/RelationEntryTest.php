<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;

use function ElPandaPe\Sentinel\Tests\auditsOf;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
    });
});

it('reads a relation entry as a diff instead of failing on it', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);

    $diff = auditsOf($this->team)->sole()->diff()->toArray();

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('/members/'.$this->members[0]->getKey())
        ->and($diff[0]['op'])->toBe('add')
        ->and($diff[0]['new'])->toEqualCanonicalizing(['expires_at' => null, 'role' => 'lead']);
});

it('reads a detach as a removal and an update as a replacement', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);
    $this->team->members()->updateExistingPivot($this->members[0]->getKey(), ['role' => 'admin']);
    $this->team->members()->detach($this->members[0]->getKey());

    $operations = auditsOf($this->team)
        ->map(static fn ($audit): mixed => $audit->diff()->toArray()[0]['op'])
        ->all();

    expect($operations)->toBe(['add', 'replace', 'remove']);
});

it('narrows a relation entry by pointer the same way an attribute entry narrows', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());
    $this->team->labels()->attach(1);

    $entries = auditsOf($this->team);

    expect($entries->first()->diffFor('members'))->toHaveCount(1)
        ->and($entries->first()->diffFor('labels'))->toBeEmpty();
});

it('serialises the lines a relation entry holds, not a rendering of them', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);

    $changes = auditsOf($this->team)->sole()->toArray()['changes'];

    expect($changes[0])->toHaveKeys(['relation', 'operation', 'related_type', 'related_id'])
        ->and($changes[0]['relation'])->toBe('members')
        ->and($changes[0]['operation'])->toBe('attach');
});

it('still serialises a model entry as a diff', function (): void {
    $team = Team::query()->create(['name' => 'Infra']);
    $team->update(['name' => 'Platform']);

    expect($team->latestAudit()->toArray()['changes'][0])->toHaveKeys(['path', 'op', 'old', 'new']);
});
