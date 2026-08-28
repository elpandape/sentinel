<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\presenter;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
    });
});

/**
 * The records come in the order the entry canonicalised them — by who was related, not by what
 * became of them — so two runs of the same sync read the same way round.
 */
it('puts the relation on the sentence and the records under it', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());
    $this->team->members()->sync([$this->members[1]->getKey()]);

    $lines = explode(PHP_EOL, presenter()->entry(auditsOf($this->team)->last()));

    expect($lines[0])->toEndWith('· members')
        ->and($lines)->toHaveCount(3)
        ->and($lines[1])->toBe('  - Member #'.$this->members[0]->getKey())
        ->and($lines[2])->toBe('  + Member #'.$this->members[1]->getKey());
});

it('marks a pivot that changed apart from one that came or went', function (): void {
    $this->team->members()->attach($this->members[0]->getKey(), ['role' => 'lead']);
    $this->team->members()->updateExistingPivot($this->members[0]->getKey(), ['role' => 'admin']);

    expect(presenter()->entry(auditsOf($this->team)->last()))
        ->toContain('  ~ Member #'.$this->members[0]->getKey());
});

it('leaves a model entry reading as one sentence', function (): void {
    $team = Team::query()->create(['name' => 'Infra']);

    expect(presenter()->entry($team->latestAudit()))->not->toContain(PHP_EOL);
});

it('reads a relation entry in spanish too', function (): void {
    app()->setLocale('es');
    $this->team->members()->attach($this->members[0]->getKey());

    $lines = explode(PHP_EOL, presenter()->entry(auditsOf($this->team)->sole()));

    expect($lines[0])->toContain('vinculó')
        ->and($lines[0])->toEndWith('· members')
        ->and($lines[1])->toBe('  + Member #'.$this->members[0]->getKey());
});

it('carries a relation entry into the timeline with its lines', function (): void {
    $this->team->members()->attach($this->members[0]->getKey());

    expect(presenter()->timeline(auditsOf($this->team)))
        ->toContain('· members')
        ->toContain('  + Member #'.$this->members[0]->getKey());
});

/**
 * Neither shape is one the package writes — the pipeline drops a relation entry with no lines, and
 * every line it does write names its relation. They are what a foreign driver or a hand-written row
 * can put in the table, and a presenter that reads the trail has to survive reading one.
 */
it('reads a relation entry that carries no lines as the sentence alone', function (): void {
    insertAudit(['id' => $id = Str::ulid()->toString(), 'audit_type' => 'relation', 'event' => 'synced']);

    expect(presenter()->entry(Audit::query()->findOrFail($id)))->not->toContain(PHP_EOL);
});

it('reads a line that names no relation without inventing one', function (): void {
    insertAudit([
        'id' => $id = Str::ulid()->toString(),
        'audit_type' => 'relation',
        'event' => 'attached',
        'changes' => json_encode([['operation' => 'attach', 'related_type' => 'member', 'related_id' => '7']]),
    ]);

    $lines = explode(PHP_EOL, presenter()->entry(Audit::query()->findOrFail($id)));

    expect($lines[0])->toEndWith('· ')
        ->and($lines[1])->toBe('  + member #7');
});
