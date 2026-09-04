<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Row;
use ElPandaPe\Sentinel\Tests\Fixtures\PretendOrigin;

it('turns a row an origin understands into an entry it could have written itself', function (): void {
    $mapping = new PretendOrigin()->map(new Row([
        'id' => '4711',
        'event' => 'updated',
        'subject_type' => 'invoice',
        'subject_id' => '77',
        'created_at' => '2024-03-04 05:06:07',
    ]));

    expect($mapping->refused)->toBeNull()
        ->and($mapping->data?->source)->toBe(Source::Import)
        ->and($mapping->data?->subject_id)->toBe('77')
        ->and($mapping->data?->capture_id)->toBe(Identity::of('pretend', '4711'))
        ->and($mapping->data?->occurred_at->format('Y'))->toBe('2024');
});

it('refuses a row with its reason instead of guessing at what it does not say', function (): void {
    $mapping = new PretendOrigin()->map(new Row(['id' => '4711', 'event' => 'updated']));

    expect($mapping->data)->toBeNull()
        ->and($mapping->refused)->toContain('says neither when nor which');
});
