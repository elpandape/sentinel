<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\SubstituteResolver;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\contextEngine;

it('lets the application replace any resolver by configuration', function (string $name): void {
    config()->set("sentinel.resolvers.{$name}.class", SubstituteResolver::class);

    expect(contextEngine()(auditData())->context['substituted'])->toBeTrue();
})->with(['actor', 'impersonator', 'tenant', 'request', 'session', 'trace', 'source', 'host', 'job', 'command']);
