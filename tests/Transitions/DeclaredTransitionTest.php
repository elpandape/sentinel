<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\MaskedTransitionSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\NarrowTransitionSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;

beforeEach(function (): void {
    $this->invoice = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);

    Audit::query()->delete();
});

it('turns an update of the declared column into a transition rather than an edit', function (): void {
    $this->invoice->update(['status' => 'published']);

    $audit = Audit::query()->firstOrFail();

    expect($audit->audit_type)->toBe('transition')
        ->and($audit->event)->toBe('transition')
        ->and($audit->metadata['transition']['attribute'] ?? null)->toBe('status');
});

it('leaves an update of any other column as the edit it is', function (): void {
    $this->invoice->update(['name' => 'renamed']);

    $audit = Audit::query()->firstOrFail();

    expect($audit->audit_type)->toBe('model')
        ->and($audit->event)->toBe('updated')
        ->and($audit->metadata)->toBeNull();
});

it('writes no entry at all when the column is set to the value it already had', function (): void {
    $this->invoice->update(['status' => 'draft']);

    expect(Audit::query()->count())->toBe(0);
});

it('keeps the whole diff on the one entry when a save moved the state and more besides', function (): void {
    $this->invoice->update(['status' => 'published', 'name' => 'renamed']);

    $audit = Audit::query()->firstOrFail();

    expect(Audit::query()->count())->toBe(1)
        ->and($audit->audit_type)->toBe('transition')
        ->and($audit->diff()->toArray())->toBe([
            ['path' => '/name', 'op' => 'replace', 'old' => 'invoice', 'new' => 'renamed'],
            ['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'published'],
        ]);
});

it('reads the two states off the line that names the declared column', function (): void {
    $this->invoice->update(['status' => 'published', 'name' => 'renamed']);

    $audit = Audit::query()->firstOrFail();
    $line = $audit->diffFor('status')->toArray()[0];

    expect($line['old'])->toBe('draft')
        ->and($line['new'])->toBe('published');
});

it('leaves a creation and a deletion alone, since neither is a move between states', function (): void {
    $subject = TransitioningSubject::query()->create(['name' => 'other', 'status' => 'draft']);
    $subject->delete();

    expect(Audit::query()->pluck('audit_type')->all())->toBe(['model', 'model']);
});

it('takes the severity configured for a transition, not the one configured for an update', function (): void {
    config()->set('sentinel.severity.events', ['transition' => 'warning', 'updated' => 'critical']);

    $this->invoice->update(['status' => 'published']);

    expect(Audit::query()->firstOrFail()->severity)->toBe(Severity::Warning);
});

it('infers the column a declared model already named, so the call need not repeat it', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

    expect(Audit::query()->firstOrFail()->getAttribute('changes'))->toBe([
        ['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'published'],
    ]);
});

it('falls back to the configured column for a model that declares none', function (): void {
    $plain = AuditedSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
    Audit::query()->delete();

    config()->set('sentinel.transitions.attribute', 'phase');

    Sentinel::transition($plain, from: 'draft', to: 'published')->record();

    expect(Audit::query()->firstOrFail()->metadata['transition']['attribute'] ?? null)->toBe('phase');
});

it('refuses a state column that is redacted, because a masked lifeline answers nothing', function (): void {
    MaskedTransitionSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
})->throws(ConfigurationException::class, 'auditRedact');

it('refuses a state column that a declared include list leaves out', function (): void {
    NarrowTransitionSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
})->throws(ConfigurationException::class, 'auditInclude');
