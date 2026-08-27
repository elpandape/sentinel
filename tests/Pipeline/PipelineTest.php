<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Tests\Fixtures\PassThroughStage;
use ElPandaPe\Sentinel\Tests\Fixtures\ReasonedDiscardingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\SecondStampingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\SilentDiscardingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\StampingStage;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('hands the entry to every stage and gives it back', function (): void {
    stagedPipeline([StampingStage::class, PassThroughStage::class, SecondStampingStage::class]);

    $audit = pipeline()->process(auditData());

    expect($audit)->toBeInstanceOf(AuditData::class)
        ->and($audit?->metadata)->toBe(['stamps' => ['first', 'second']]);
});

it('runs the stages in the order the list declares them', function (): void {
    stagedPipeline([SecondStampingStage::class, StampingStage::class]);

    expect(pipeline()->process(auditData())?->metadata)->toBe(['stamps' => ['second', 'first']]);
});

it('gives back the same object, not a copy of it', function (): void {
    stagedPipeline([PassThroughStage::class]);

    $data = auditData();

    expect(pipeline()->process($data))->toBe($data);
});

it('discards the entry when a stage returns null', function (): void {
    stagedPipeline([StampingStage::class, SilentDiscardingStage::class, SecondStampingStage::class]);

    expect(pipeline()->process(auditData()))->toBeNull();
});

it('stops the entry at the stage that discarded it', function (): void {
    stagedPipeline([SilentDiscardingStage::class, StampingStage::class]);

    $data = auditData();

    expect(pipeline()->process($data))->toBeNull()
        ->and($data->metadata)->toBeNull();
});

it('names the first stage to discard, not the ones the null travels back through', function (): void {
    Event::fake();

    stagedPipeline([PassThroughStage::class, SilentDiscardingStage::class]);

    pipeline()->process(auditData());

    Event::assertDispatched(
        AuditDiscarded::class,
        static fn (AuditDiscarded $event): bool => $event->stage === SilentDiscardingStage::class,
    );
});

it('carries the reason a stage gave for the discard', function (): void {
    Event::fake();

    stagedPipeline([ReasonedDiscardingStage::class]);

    pipeline()->process(auditData(['subject_type' => 'user', 'subject_id' => '7']));

    Event::assertDispatched(AuditDiscarded::class, static fn (AuditDiscarded $event): bool => $event->reason === ReasonedDiscardingStage::REASON
        && $event->stage === ReasonedDiscardingStage::class
        && $event->subjectType === 'user'
        && $event->subjectId === '7'
        && $event->auditType === 'model'
        && $event->event === 'created');
});

it('dispatches nothing when the entry settles', function (): void {
    Event::fake();

    stagedPipeline([PassThroughStage::class]);

    pipeline()->process(auditData());

    Event::assertNotDispatched(AuditDiscarded::class);
});

it('forgets a discard once the pass that produced it is over', function (): void {
    Event::fake();

    stagedPipeline([SilentDiscardingStage::class]);
    pipeline()->process(auditData());

    stagedPipeline([PassThroughStage::class]);
    pipeline()->process(auditData());

    Event::assertDispatchedTimes(AuditDiscarded::class, 1);
});
