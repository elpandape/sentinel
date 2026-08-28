<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\GovernedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\StructuredStateSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;
use ElPandaPe\Sentinel\Transitions\IllegalTransition;

beforeEach(function (): void {
    $this->invoice = GovernedSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);

    Audit::query()->delete();
});

it('records a move the model says it makes', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

    expect(Audit::query()->count())->toBe(1);
});

it('refuses a move the model does not make, and writes nothing', function (): void {
    try {
        Sentinel::transition($this->invoice, from: 'draft', to: 'archived')->record();
    } catch (IllegalTransition) {
        // The empty trail below is the assertion; the exception is what this test provokes.
    }

    expect(Audit::query()->count())->toBe(0);
});

it('names the subject, the column and the two states in the refusal', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'archived')->record();
})->throws(IllegalTransition::class, 'GovernedSubject #1 cannot move its status from draft to archived.');

it('says a column held nothing rather than calling it a state named blank', function (): void {
    $blank = GovernedSubject::query()->create(['name' => 'other']);

    Sentinel::transition($blank, from: null, to: 'archived')->record();
})->throws(IllegalTransition::class, 'from nothing to archived');

it('speaks the language the application is set to', function (): void {
    app()->setLocale('es');

    Sentinel::transition($this->invoice, from: 'draft', to: 'archived')->record();
})->throws(IllegalTransition::class, 'no puede mover su status de draft a archived');

it('abandons the save itself when an update tries an illegal move', function (): void {
    try {
        $this->invoice->update(['status' => 'archived']);
    } catch (IllegalTransition) {
        // Same shape as above: what is asserted is that neither the row nor the trail moved.
    }

    expect($this->invoice->fresh()?->getAttribute('status'))->toBe('draft')
        ->and(Audit::query()->count())->toBe(0);
});

it('lets a legal update through and writes it as the transition it is', function (): void {
    $this->invoice->update(['status' => 'published']);

    expect($this->invoice->fresh()?->getAttribute('status'))->toBe('published')
        ->and(Audit::query()->firstOrFail()->audit_type)->toBe('transition');
});

it('reads a column that held nothing as a move from nothing', function (): void {
    $fresh = GovernedSubject::query()->create(['name' => 'other']);
    Audit::query()->delete();

    $fresh->update(['status' => 'draft']);

    expect(Audit::query()->firstOrFail()->diffFor('status')->toArray()[0]['old'])->toBeNull();
});

it('governs nothing while auditing is paused', function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->invoice->update(['status' => 'archived']);
    });

    expect($this->invoice->fresh()?->getAttribute('status'))->toBe('archived');
});

it('leaves a model that declares no machine free to move however it likes', function (): void {
    $plain = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
    Audit::query()->delete();

    $plain->update(['status' => 'whatever']);

    expect(Audit::query()->firstOrFail()->audit_type)->toBe('transition');
});

it('leaves an edit of any other column unvetted', function (): void {
    $this->invoice->update(['name' => 'renamed']);

    expect(Audit::query()->firstOrFail()->event)->toBe('updated');
});

it('leaves a column that holds a structure to be the ordinary edit it is', function (): void {
    $subject = StructuredStateSubject::query()->create(['name' => 'invoice', 'options' => ['a' => 1]]);
    Audit::query()->delete();

    $subject->update(['options' => ['a' => 2]]);

    expect(Audit::query()->firstOrFail()->event)->toBe('updated');
});
