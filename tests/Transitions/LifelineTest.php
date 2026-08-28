<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;
use ElPandaPe\Sentinel\Transitions\Transition;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;

beforeEach(function (): void {
    $this->invoice = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);

    Audit::query()->delete();

    CarbonImmutable::setTestNow('2026-08-01 10:00:00');
    Sentinel::transition($this->invoice, from: 'draft', to: 'pending')->reason('Sent for review')->record();

    CarbonImmutable::setTestNow('2026-08-01 12:00:00');
    Sentinel::transition($this->invoice, from: 'pending', to: 'approved')->record();

    CarbonImmutable::setTestNow('2026-08-02 12:00:00');
    Sentinel::transition($this->invoice, from: 'approved', to: 'paid')->record();

    CarbonImmutable::setTestNow();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hands back every state the record moved through, in the order it moved through them', function (): void {
    $lifeline = Sentinel::transitions()->for($this->invoice)->get();

    expect($lifeline->map(static fn (Transition $step): string => "{$step->from} → {$step->to}")->all())->toBe([
        'draft → pending',
        'pending → approved',
        'approved → paid',
    ]);
});

it('says how long the record had been in the state it just left', function (): void {
    $lifeline = Sentinel::transitions()->for($this->invoice)->get();

    expect($lifeline[0]->since)->toBeNull()
        ->and($lifeline[1]->since?->totalHours)->toBe(2.0)
        ->and($lifeline[2]->since?->totalHours)->toBe(24.0);
});

it('keeps the interval pointing backwards in time when the newest is asked for first', function (): void {
    $lifeline = Sentinel::transitions()->for($this->invoice)->latest()->get();

    expect($lifeline->map(static fn (Transition $step): ?string => $step->to)->all())
        ->toBe(['paid', 'approved', 'pending'])
        ->and($lifeline[0]->since?->totalHours)->toBe(24.0)
        ->and($lifeline[2]->since)->toBeNull();
});

it('carries the column, the reason and the entry each step came from', function (): void {
    $step = Sentinel::transitions()->for($this->invoice)->get()->firstOrFail();

    expect($step->attribute)->toBe('status')
        ->and($step->reason)->toBe('Sent for review')
        ->and($step->entry)->toBeInstanceOf(Audit::class)
        ->and($step->occurredAt->toDateTimeString())->toBe('2026-08-01 10:00:00');
});

it('leaves the reason null on a step that was given none', function (): void {
    expect(Sentinel::transitions()->for($this->invoice)->get()[1]->reason)->toBeNull();
});

it('carries whoever the step was attributed to', function (): void {
    $approver = ActingUser::query()->create(['name' => 'approver']);

    Sentinel::transition($this->invoice, from: 'paid', to: 'settled')->actor($approver)->record();

    $lifeline = Sentinel::transitions()->for($this->invoice)->get();

    expect($lifeline[0]->actor)->toBeNull()
        ->and($lifeline[3]->actor?->id)->toBe((string) $approver->getKey());
});

it('sees only transitions, never the other kinds of entry', function (): void {
    Sentinel::event('invoice.approved')->subject($this->invoice)->record();
    $this->invoice->update(['name' => 'renamed']);

    expect(Sentinel::transitions()->for($this->invoice)->get())->toHaveCount(3);
});

it('narrows by who moved it and by when', function (): void {
    expect(Sentinel::transitions()->for($this->invoice)->between(
        new DateTimeImmutable('2026-08-01 00:00:00'),
        new DateTimeImmutable('2026-08-01 23:59:59'),
    )->get())->toHaveCount(2)
        ->and(Sentinel::transitions()->by(ActingUser::class, 999)->get())->toBeEmpty();
});

it('asks for a prefix on purpose the way the trail does', function (): void {
    expect(Sentinel::transitions()->for($this->invoice)->take(2)->get())->toHaveCount(2);
});

it('drops back to the query underneath for everything a lifeline is not', function (): void {
    $entries = Sentinel::transitions()->for($this->invoice)->entries();

    expect($entries)->toBeInstanceOf(AuditQuery::class)
        ->and($entries->paginate(2)->hasMore)->toBeTrue();
});

it('reads a transition that names no column as a step with no states', function (): void {
    DB::table(auditsTable())->insert(auditRow([
        'stream' => 'orphan',
        'audit_type' => 'transition',
        'event' => 'transition',
        'subject_type' => $this->invoice->getMorphClass(),
        'subject_id' => (string) $this->invoice->getKey(),
        'occurred_at' => '2026-08-03 10:00:00.000000',
        'created_at' => '2026-08-03 10:00:00.000000',
    ]));

    $step = Sentinel::transitions()->for($this->invoice)->get()->last();

    expect($step?->attribute)->toBe('')
        ->and($step?->from)->toBeNull()
        ->and($step?->to)->toBeNull();
});
