<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SelectiveSubject;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([FilterUnchanged::class]);
});

it('discards an update whose comparison found nothing', function (): void {
    expect(pipeline()->process(auditData(['event' => 'updated', 'changes' => []])))->toBeNull();
});

it('keeps an update that changed something', function (): void {
    $data = auditData(['event' => 'updated', 'changes' => [['path' => '/name', 'op' => 'replace', 'old' => 'a', 'new' => 'b']]]);

    expect(pipeline()->process($data))->toBe($data);
});

it('keeps an event that had nothing to compare', function (): void {
    $data = auditData(['event' => 'updated', 'changes' => null]);

    expect(pipeline()->process($data))->toBe($data);
});

it('keeps a creation with no comparable fields, because creating still happened', function (): void {
    $data = auditData(['event' => 'created', 'changes' => []]);

    expect(pipeline()->process($data))->toBe($data);
});

it('keeps a restore whose only changed column is not audited', function (): void {
    $data = auditData(['event' => 'restored', 'changes' => []]);

    expect(pipeline()->process($data))->toBe($data);
});

it('says why it discarded', function (): void {
    Event::fake();

    pipeline()->process(auditData(['event' => 'updated', 'changes' => [], 'subject_type' => 'user', 'subject_id' => '7']));

    Event::assertDispatched(AuditDiscarded::class, static fn (AuditDiscarded $event): bool => $event->reason === FilterUnchanged::REASON
        && $event->stage === FilterUnchanged::class
        && $event->message() === 'The updated to user 7 changed nothing that is audited, so no entry was written.');
});

it('runs before every other stage the package ships', function (): void {
    expect(Pipeline::DEFAULT_STAGES[0])->toBe(FilterUnchanged::class);
});

it('writes no entry when the only column that moved is excluded from the snapshot', function (): void {
    stagedPipeline([]);

    $subject = SelectiveSubject::query()->create(['name' => 'Ada', 'status' => 'draft']);
    $subject->update(['status' => 'published']);

    expect(auditsOf($subject))->toHaveCount(1);
});

it('still writes an entry when an audited column moves', function (): void {
    stagedPipeline([]);

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject))->toHaveCount(2);
});

it('leaves no gap in the chain when it discards', function (): void {
    stagedPipeline([]);

    $subject = SelectiveSubject::query()->create(['name' => 'Ada', 'status' => 'draft']);
    $subject->update(['status' => 'published']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject)->pluck('sequence')->all())->toBe([1, 2]);
});
