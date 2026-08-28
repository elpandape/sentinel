<?php

declare(strict_types=1);

use Pest\Rector\Rules\ChainExpectCallsRector;
use Pest\Rector\Rules\ConvertAssertToExpectRector;
use Pest\Rector\Rules\ConvertExpectExceptionToThrowRector;
use Pest\Rector\Rules\SimplifyToLiteralBooleanRector;
use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/database', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        // src/Testing publishes plain PHPUnit test cases meant for third-party
        // drivers to extend, so it keeps native assertions instead of Pest's.
        ConvertAssertToExpectRector::class => [__DIR__.'/src/Testing'],
        ChainExpectCallsRector::class => [__DIR__.'/src/Testing'],
        SimplifyToLiteralBooleanRector::class => [__DIR__.'/src/Testing'],
        ConvertExpectExceptionToThrowRector::class => [__DIR__.'/src/Testing'],
    ]);
