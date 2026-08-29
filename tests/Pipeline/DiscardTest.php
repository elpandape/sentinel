<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Exceptions\DiscardException;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Pipeline\Discarded;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\NestingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\ReasonedDiscardingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\SilentDiscardingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\ThrowingStage;
use Illuminate\Contracts\Events\Dispatcher;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\discard;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('refuses a discard asked for outside the pipeline', function (): void {
    discard()->because('too late');
})->throws(DiscardException::class, 'too late');

it('says the ledger has already assigned sequence by then', function (): void {
    expect(fn (): mixed => discard()->because('too late'))
        ->toThrow(DiscardException::class, 'verifyIntegrity()');
});

it('refuses a discard asked for once the pass is over', function (): void {
    stagedPipeline([SilentDiscardingStage::class]);
    pipeline()->process(auditData());

    discard()->because('after the fact');
})->throws(DiscardException::class);

it('is not running until a pass begins', function (): void {
    $discard = new Discard;

    expect($discard->running())->toBeFalse();

    $discard->begin();

    expect($discard->running())->toBeTrue();

    $discard->end();

    expect($discard->running())->toBeFalse();
});

it('hands the outer pass back untouched when one began inside it', function (): void {
    $discard = new Discard;

    $discard->begin();
    $discard->because('the outer reason');
    $discard->at(SilentDiscardingStage::class);

    $discard->begin();
    $discard->because('the inner reason');
    $discard->at(ReasonedDiscardingStage::class);

    expect($discard->end())->toEqual(new Discarded(ReasonedDiscardingStage::class, 'the inner reason'))
        ->and($discard->running())->toBeTrue()
        ->and($discard->end())->toEqual(new Discarded(SilentDiscardingStage::class, 'the outer reason'));
});

it('lets a pass that began inside another one keep its own empty verdict', function (): void {
    $discard = new Discard;

    $discard->begin();
    $discard->at(SilentDiscardingStage::class);

    $discard->begin();

    expect($discard->end())->toBeNull()
        ->and($discard->running())->toBeTrue();
});

it('closes the pass even when a stage throws on the way through', function (): void {
    stagedPipeline([ThrowingStage::class]);

    rescue(static fn (): mixed => pipeline()->process(auditData()), report: false);

    expect(discard()->running())->toBeFalse();
});

it('still lets a later stage discard after one of them wrote to an audited model', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    stagedPipeline([NestingStage::class, ReasonedDiscardingStage::class]);

    $discarded = null;

    app(Dispatcher::class)->listen(AuditDiscarded::class, static function (AuditDiscarded $event) use (&$discarded): void {
        $discarded ??= $event;
    });

    $record->update(['name' => 'Grace']);

    expect($discarded?->reason)->toBe(ReasonedDiscardingStage::REASON)
        ->and($discarded?->event)->toBe('created');
});

it('reports nothing for a pass that never began', function (): void {
    expect((new Discard)->end())->toBeNull();
});

it('reports no discard when no stage returned null', function (): void {
    $discard = new Discard;
    $discard->begin();

    expect($discard->end())->toBeNull();
});

it('keeps the reason and the stage of the first discard, not of the last', function (): void {
    $discard = new Discard;
    $discard->begin();
    $discard->because('first');
    $discard->at(SilentDiscardingStage::class);
    $discard->because('second');
    $discard->at(ReasonedDiscardingStage::class);

    expect($discard->end())->toEqual(new Discarded(SilentDiscardingStage::class, 'first'));
});

it('hands null back to the stage so returning it stays the mechanism', function (): void {
    $discard = new Discard;
    $discard->begin();

    expect($discard->at(SilentDiscardingStage::class))->toBeNull();
});

it('falls back to an unspecified reason when the stage gave none', function (): void {
    $discard = new Discard;
    $discard->begin();
    $discard->at(SilentDiscardingStage::class);

    expect($discard->end())->toEqual(new Discarded(SilentDiscardingStage::class, 'unspecified'));
});

it('translates the reason the package ships', function (): void {
    $event = new AuditDiscarded('model', 'updated', 'user', '7', SilentDiscardingStage::class, 'unspecified');

    expect($event->message())
        ->toBe('Stage SilentDiscardingStage discarded the updated entry for user 7 before it reached the ledger.');
});

it('hands back a reason it has no translation for', function (): void {
    $event = new AuditDiscarded('model', 'updated', null, null, ReasonedDiscardingStage::class, 'the fixture said so');

    expect($event->message())->toBe('the fixture said so');
});
