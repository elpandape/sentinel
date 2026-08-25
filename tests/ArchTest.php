<?php

declare(strict_types=1);

use function ElPandaPe\Sentinel\Tests\phpFilesOffending;

arch('source uses strict types')
    ->expect('ElPandaPe\Sentinel')
    ->toUseStrictTypes();

arch('no debugging functions left behind')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums live in the Enums namespace')
    ->expect('ElPandaPe\Sentinel\Enums')
    ->toBeEnums();

arch('exceptions live in the Exceptions namespace')
    ->expect('ElPandaPe\Sentinel\Exceptions')
    ->toImplement(Throwable::class);

it('leaves no doc block stranded above another', function (): void {
    expect(phpFilesOffending('#\*/\s*\n\s*/\*\*#'))->toBeEmpty();
});

it('cites no tool-generated identifier in a comment', function (): void {
    expect(phpFilesOffending('#(?://|\*)[^\n]*\b[0-9a-f]{12,}\b#'))->toBeEmpty();
});
