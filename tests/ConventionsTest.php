<?php

declare(strict_types=1);

use function ElPandaPe\Sentinel\Tests\phpFilesOffending;
use function ElPandaPe\Sentinel\Tests\translationKeys;

it('holds no mutable static state in the source', function (): void {
    expect(phpFilesOffending(
        '#^[ \t]*(?:public |protected |private )?static (?!function|fn\b|::)#m',
        dirname(__DIR__).'/src',
    ))->toBeEmpty();
});

it('leaves no doc block stranded above another', function (): void {
    expect(phpFilesOffending('#\*/\s*\n\s*/\*\*#'))->toBeEmpty();
});

it('cites no tool-generated identifier in a comment', function (): void {
    expect(phpFilesOffending('#(?:(?<!:)//|^\s*\*)[^\n]*\b[0-9a-f]{12,}\b#m'))->toBeEmpty();
});

it('keeps the two language files carrying the same keys', function (): void {
    $en = require dirname(__DIR__).'/resources/lang/en/sentinel.php';
    $es = require dirname(__DIR__).'/resources/lang/es/sentinel.php';

    expect(translationKeys($es))->toBe(translationKeys($en))
        ->and(translationKeys($en))->not->toBeEmpty();
});

it('ships a default ledger that outlives the request that wrote to it', function (): void {
    expect(config('sentinel.ledger.default'))->toBe('database');
});
