<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies;
use ElPandaPe\Sentinel\Support\Policies;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([EnforcePolicies::class]);
});

afterEach(function (): void {
    app(Policies::class)->forget();
});

it('settles the entry when no policy was registered', function (): void {
    $data = auditData();

    expect(pipeline()->process($data))->toBe($data);
});

it('settles the entry when every policy allows it', function (): void {
    Sentinel::filter(static fn (AuditData $audit): bool => true);
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->audit_type === 'model');

    expect(pipeline()->process(auditData()))->toBeInstanceOf(AuditData::class);
});

it('discards the entry when one policy refuses it', function (): void {
    Sentinel::filter(static fn (AuditData $audit): bool => true);
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->event !== 'created');

    expect(pipeline()->process(auditData()))->toBeNull();
});

it('lets a policy decide on the subject it was given', function (): void {
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_id !== '7');

    expect(pipeline()->process(auditData(['subject_id' => '7'])))->toBeNull()
        ->and(pipeline()->process(auditData(['subject_id' => '8'])))->not->toBeNull();
});

it('says a policy was what discarded it', function (): void {
    Event::fake();

    Sentinel::filter(static fn (AuditData $audit): bool => false);

    pipeline()->process(auditData(['subject_type' => 'user', 'subject_id' => '7']));

    Event::assertDispatched(AuditDiscarded::class, static fn (AuditDiscarded $event): bool => $event->reason === EnforcePolicies::REASON
        && $event->stage === EnforcePolicies::class
        && $event->message() === 'A policy discarded the created entry for user 7 before it reached the ledger.');
});

it('has the last word, after the entry has been transformed', function (): void {
    expect(array_key_last(Pipeline::DEFAULT_STAGES))
        ->and(Pipeline::DEFAULT_STAGES[array_key_last(Pipeline::DEFAULT_STAGES)])->toBe(EnforcePolicies::class);
});

it('writes no entry, and leaves no gap, when a policy refuses a real capture', function (): void {
    stagedPipeline([]);

    Sentinel::filter(static fn (AuditData $audit): bool => $audit->event !== 'updated');

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->delete();

    expect(auditsOf($subject)->pluck('event')->all())->toBe(['created', 'deleted'])
        ->and(auditsOf($subject)->pluck('sequence')->all())->toBe([1, 2]);
});

it('keeps a policy across the scopes a worker goes through', function (): void {
    Sentinel::filter(static fn (AuditData $audit): bool => false);

    app()->forgetScopedInstances();

    expect(pipeline()->process(auditData()))->toBeNull();
});
