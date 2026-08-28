<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\RelationCapture;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\Label;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\NarrowLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = Team::query()->create(['name' => 'Ops']);
        $this->members = collect(['Ada', 'Linus'])
            ->map(static fn (string $name): Member => Member::query()->create(['name' => $name]));
        $this->label = Label::query()->create(['name' => 'urgent']);
    });

    $this->team->members()->attach($this->members[0]->getKey());
    $this->team->guests()->attach($this->members[1]->getKey());
    $this->team->members()->detach($this->members[0]->getKey());
    $this->team->labels()->attach($this->label->getKey());
});

it('answers with the history of one relation and nothing else', function (): void {
    expect($this->team->relationHistory('members')->get())->toHaveCount(2)
        ->and($this->team->relationHistory('guests')->get())->toHaveCount(1)
        ->and($this->team->relationHistory('labels')->get())->toHaveCount(1);
});

it('narrows a relation to what happened to it', function (): void {
    $attached = $this->team->relationHistory('members')->whereOperation(RelationOperation::Attach)->get();

    expect($attached)->toHaveCount(1)
        ->and($attached->sole()->event)->toBe('attached');
});

it('takes the operation by name as well as by case', function (): void {
    expect($this->team->relationHistory('members')->whereOperation('detach')->get())->toHaveCount(1);
});

it('asks for any of the operations named, and accumulates across calls', function (): void {
    expect($this->team->relationHistory('members')->whereOperation('attach', 'detach')->get())->toHaveCount(2)
        ->and($this->team->relationHistory('members')->whereOperation('attach')->whereOperation('detach')->get())
        ->toHaveCount(2);
});

it('refuses an operation that is not one of the three', function (): void {
    expect(fn () => $this->team->relationHistory('members')->whereOperation('deleted'))
        ->toThrow(QueryException::class);
});

it('narrows to the record that was related', function (): void {
    expect(Sentinel::audits()->whereRelated($this->members[0])->get())->toHaveCount(2)
        ->and(Sentinel::audits()->whereRelated($this->members[1])->get())->toHaveCount(1)
        ->and(Sentinel::audits()->whereRelated($this->label)->get())->toHaveCount(1);
});

/**
 * The reason the three parts are one criterion: asked separately, this would be answered by the
 * entry that detached Ada, because that entry also has a line about Ada and a line about attaching.
 */
it('answers only when one line satisfies every part at once', function (): void {
    $this->team->members()->sync([$this->members[0]->getKey(), $this->members[1]->getKey()]);
    $this->team->members()->sync([$this->members[1]->getKey()]);

    $detachedAda = Sentinel::audits()
        ->whereRelation('members')
        ->whereRelated($this->members[0])
        ->whereOperation(RelationOperation::Detach)
        ->get();

    expect($detachedAda)->toHaveCount(2);
});

it('composes with every other filter and with the timeline', function (): void {
    expect(Sentinel::timeline()->for($this->team)->whereRelation('members')->get())->toHaveCount(2)
        ->and(Sentinel::audits()->whereEvent('attached')->whereRelation('members')->get())->toHaveCount(1);
});

it('answers nothing for a relation nobody touched', function (): void {
    expect($this->team->relationHistory('nobody')->get())->toBeEmpty();
});

it('leaves a model entry out of every relation answer', function (): void {
    Team::query()->create(['name' => 'Infra']);

    expect(Sentinel::audits()->whereRelation('members')->get())->toHaveCount(2);
});

it('resolves the same criteria with no database at all', function (): void {
    $ledger = app(MemoryLedger::class);

    $ledger->write(auditData([
        'audit_type' => RelationCapture::AUDIT_TYPE,
        'event' => 'synced',
        'subject_type' => 'team',
        'subject_id' => '1',
        'changes' => [
            ['relation' => 'members', 'operation' => 'attach', 'related_type' => 'member', 'related_id' => '7'],
            ['relation' => 'members', 'operation' => 'detach', 'related_type' => 'member', 'related_id' => '9'],
        ],
    ]));

    expect(auditQuery($ledger)->whereRelation('members')->get())->toHaveCount(1)
        ->and(auditQuery($ledger)->whereRelation('guests')->get())->toBeEmpty()
        ->and(auditQuery($ledger)->whereRelated('member', 7)->whereOperation('attach')->get())->toHaveCount(1)
        ->and(auditQuery($ledger)->whereRelated('member', 7)->whereOperation('detach')->get())->toBeEmpty();
});

it('refuses the three filters on a driver that does not declare them', function (Filter $filter): void {
    $narrow = auditQuery(new NarrowLedger(app(MemoryLedger::class)));

    expect(fn () => match ($filter) {
        Filter::Relation => $narrow->whereRelation('members'),
        Filter::Related => $narrow->whereRelated('member', 1),
        default => $narrow->whereOperation('attach'),
    })->toThrow(LedgerException::class);
})->with([Filter::Relation, Filter::Related, Filter::Operation]);
