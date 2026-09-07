<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\RetentionHold;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\retireEntries;
use function ElPandaPe\Sentinel\Tests\seedAudit;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;

beforeEach(function (): void {
    seedTheReferenceChain();
});

$later = new CarbonImmutable('2026-09-30 12:00:00');

it('offers an anchored window every entry of which has been released', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->isEmpty())->toBeFalse()
        ->and($frontier->windows)->toHaveCount(1)
        ->and($frontier->windows[0]->from)->toBe(1)
        ->and($frontier->windows[0]->to)->toBe(4)
        ->and($frontier->entries())->toBe(4);
});

it('never offers the window holding the entry the next write links to', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 8);

    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->isEmpty())->toBeTrue()
        ->and($frontier->hold)->toBe(RetentionHold::Tail);
});

it('says so when nothing is declared', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    $frontier = frontiers([])->of(ReferenceChain::STREAM, $later);

    expect($frontier->hold)->toBe(RetentionHold::Undeclared)
        ->and($frontier->message())->toContain('keeps everything');
});

it('says so when the stream has no anchors', function () use ($later): void {
    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->hold)->toBe(RetentionHold::Unanchored)
        ->and($frontier->message())->toContain('no anchors');
});

it('names the entry that is holding a window it cannot offer', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    $frontier = frontiers(['model' => '10 years'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->hold)->toBe(RetentionHold::Retained)
        ->and($frontier->heldAt)->toBe(1)
        ->and($frontier->heldBy)->toBe('model:subject')
        ->and($frontier->message())->toContain('model:subject');
});

it('holds a whole window for one entry inside it that is still kept', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    $frontier = frontiers(['model' => '1 day', 'model:subject' => '10 years'])
        ->of(ReferenceChain::STREAM, $later);

    expect($frontier->isEmpty())->toBeTrue()
        ->and($frontier->hold)->toBe(RetentionHold::Retained);
});

it('answers about one stream without reading another', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);
    anchor(ReferenceChain::FORK, 2);

    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::FORK, $later);

    expect($frontier->isEmpty())->toBeTrue()
        ->and($frontier->hold)->toBe(RetentionHold::Tail);
});

it('gives back no message at all when it is offering something', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later)->message())->toBeEmpty();
});

it('offers nothing but the tail once the earlier windows have already gone', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);
    retireEntries(ReferenceChain::STREAM, 1, 4);

    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->isEmpty())->toBeTrue()
        ->and($frontier->hold)->toBe(RetentionHold::Tail);
});

it('names the entry retention is still holding, not the first one of its window', function () use ($later): void {
    seedAudit(1, ['created_at' => '2020-01-01 00:00:00.000000']);
    seedAudit(2, ['audit_type' => 'auth', 'subject_type' => '', 'created_at' => '2026-09-29 12:00:00.000000']);
    seedAudit(3, ['created_at' => '2020-01-01 00:00:00.000000']);

    anchor('global', 2);

    $frontier = frontiers(['model' => '1 day', 'auth' => '10 years'])->of('global', $later);

    expect($frontier->hold)->toBe(RetentionHold::Retained)
        ->and($frontier->heldAt)->toBe(2)
        ->and($frontier->heldBy)->toBe('auth')
        ->and($frontier->message())->toContain('auth');
});

it('names nothing as holding a stream it is offering a window of', function () use ($later): void {
    anchor(ReferenceChain::STREAM, 4);

    $frontier = frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later);

    expect($frontier->hold)->toBeNull()
        ->and($frontier->heldAt)->toBe(0)
        ->and($frontier->heldBy)->toBeEmpty();
});
