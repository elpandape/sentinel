<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\PureStatus;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;
use ElPandaPe\Sentinel\Transitions\TransitionBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->invoice = AuditedSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);

    Audit::query()->delete();
});

it('settles a state change as an entry of its own kind', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->audit_type)->toBe('transition')
        ->and($audit->event)->toBe('transition')
        ->and($audit->subject_type)->toBe($this->invoice->getMorphClass())
        ->and($audit->subject_id)->toBe((string) $this->invoice->getKey());
});

it('reads a backed enum, a pure enum and a string into the same entry', function (): void {
    Sentinel::transition($this->invoice, from: SubjectStatus::Draft, to: SubjectStatus::Published)->record();
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();
    Sentinel::transition($this->invoice, from: PureStatus::Draft, to: PureStatus::Published)->record();

    $lines = Audit::query()->orderBy('sequence')->get()
        ->map(static fn (Audit $audit): mixed => $audit->getAttribute('changes'))
        ->all();

    expect($lines[0])->toBe($lines[1])
        ->and($lines[2])->toBe([['path' => '/status', 'op' => 'replace', 'old' => 'Draft', 'new' => 'Published']]);
});

it('writes nothing until the terminal is called', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->reason('Budget confirmed');

    expect(Audit::query()->count())->toBe(0);
});

it('hands back the builder from every modifier, so the call chains', function (): void {
    $pending = Sentinel::transition($this->invoice, from: 'draft', to: 'published');

    expect($pending->on('state'))->toBeInstanceOf(TransitionBuilder::class)
        ->and($pending->reason('Budget confirmed'))->toBeInstanceOf(TransitionBuilder::class)
        ->and($pending->actor('system', 'cron'))->toBeInstanceOf(TransitionBuilder::class)
        ->and($pending->severity(Severity::Notice))->toBeInstanceOf(TransitionBuilder::class)
        ->and($pending->tags(['billing']))->toBeInstanceOf(TransitionBuilder::class)
        ->and($pending->metadata(['approved_by' => 'finance']))->toBeInstanceOf(TransitionBuilder::class);
});

it('files the two states where a field filter still finds them', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->getAttribute('changes'))->toBe([
        ['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'published'],
    ])->and(Sentinel::audits()->for($this->invoice)->whereFieldChanged('status')->get())->toHaveCount(1);
});

it('names the column the call gave it over the one the configuration assumes', function (): void {
    Sentinel::transition($this->invoice, from: 'open', to: 'closed')->on('phase')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->getAttribute('changes'))->toBe([
        ['path' => '/phase', 'op' => 'replace', 'old' => 'open', 'new' => 'closed'],
    ])->and($audit->metadata['transition']['attribute'] ?? null)->toBe('phase');
});

it('keeps the reason with the column it is about, and out of the caller own keys', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')
        ->reason('Budget confirmed')
        ->metadata(['reason' => 'the caller meant something else by this'])
        ->record();

    expect(Audit::query()->firstOrFail()->metadata)->toBe([
        'reason' => 'the caller meant something else by this',
        'transition' => ['attribute' => 'status', 'reason' => 'Budget confirmed'],
    ]);
});

it('leaves the reason out entirely when none was given', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

    expect(Audit::query()->firstOrFail()->metadata)->toBe(['transition' => ['attribute' => 'status']]);
});

it('takes the actor it was named rather than the resolved one', function (): void {
    $approver = ActingUser::query()->create(['name' => 'approver']);
    $this->actingAs(ActingUser::query()->create(['name' => 'someone else']));

    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->actor($approver)->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->actor_type)->toBe($approver->getMorphClass())
        ->and($audit->actor_id)->toBe((string) $approver->getKey());
});

it('takes the severity it was given, and the configured default otherwise', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->severity(Severity::Critical)->record();

    config()->set('sentinel.severity.events', ['transition' => 'warning']);
    Sentinel::transition($this->invoice, from: 'published', to: 'archived')->record();

    $entries = Audit::query()->orderBy('sequence')->get();

    expect($entries[0]->severity)->toBe(Severity::Critical)
        ->and($entries[1]->severity)->toBe(Severity::Warning);
});

it('files the labels where whereTag can still find them', function (): void {
    Sentinel::transition($this->invoice, from: 'draft', to: 'published')->tags(['billing'])->record();

    expect(Sentinel::audits()->whereTag('billing')->get())->toHaveCount(1);
});

it('refuses a subject that has no key, because no entry can point at it', function (): void {
    Sentinel::transition(new AuditedSubject, from: 'draft', to: 'published');
})->throws(QueryException::class);

it('leaves no record of a state change the transaction rolled back', function (): void {
    try {
        DB::transaction(function (): void {
            Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();

            throw new RuntimeException('nope');
        });
    } catch (RuntimeException) {
        // The rollback is the subject of the test; the exception is how it is provoked.
    }

    expect(Audit::query()->count())->toBe(0);
});

it('writes nothing at all while auditing is paused', function (): void {
    Sentinel::withoutAuditing(function (): void {
        Sentinel::transition($this->invoice, from: 'draft', to: 'published')->record();
    });

    expect(Audit::query()->count())->toBe(0);
});
