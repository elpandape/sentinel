<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;

use function ElPandaPe\Sentinel\Tests\presenter;

beforeEach(function (): void {
    $this->invoice = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);

    Audit::query()->delete();
});

it('says which two states a record moved between', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'paid')->record();

    expect(presenter()->entry(Audit::query()->firstOrFail()))
        ->toBe('Someone moved TransitioningSubject #1 · draft → paid');
});

it('names whoever moved it', function (): void {
    $approver = ActingUser::query()->create(['name' => 'approver']);

    Sentinel::transition($this->invoice, from: 'draft', to: 'paid')->actor($approver)->record();

    expect(presenter()->entry(Audit::query()->firstOrFail()))
        ->toBe('ActingUser #1 moved TransitioningSubject #1 · draft → paid');
});

it('says a record that had no state came from nothing', function (): void {
    $blank = TransitioningSubject::query()->create(['name' => 'other']);
    Audit::query()->delete();

    $blank->update(['status' => 'draft']);

    expect(presenter()->entry(Audit::query()->firstOrFail()))
        ->toBe('Someone moved TransitioningSubject #2 · nothing → draft');
});

it('reads an update that moved the state as the transition it is', function (): void {
    $this->invoice->update(['status' => 'published', 'name' => 'renamed']);

    expect(presenter()->entry(Audit::query()->firstOrFail()))
        ->toBe('Someone moved TransitioningSubject #1 · draft → published');
});

it('puts the transition in the timeline in its own place in time', function (): void {
    CarbonImmutable::setTestNow('2026-08-01 09:30:00');
    Sentinel::event('invoice.received')->subject($this->invoice)->record();

    CarbonImmutable::setTestNow('2026-08-01 11:45:00');
    Sentinel::transition($this->invoice, from: 'draft', to: 'paid')->record();

    CarbonImmutable::setTestNow();

    expect(presenter()->timeline(Sentinel::timeline()->for($this->invoice)->get()))->toBe(implode(PHP_EOL, [
        '09:30  Someone invoice.received TransitioningSubject #1',
        '11:45  Someone moved TransitioningSubject #1 · draft → paid',
    ]));
});

it('speaks the language the application is set to', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'paid')->record();

    app()->setLocale('es');

    expect(presenter()->entry(Audit::query()->firstOrFail()))
        ->toBe('Alguien movió TransitioningSubject #1 · draft → paid');
});
