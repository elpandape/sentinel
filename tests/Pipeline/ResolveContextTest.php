<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\contextEngine;
use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('resolves context from inside the pipeline', function (): void {
    httpRequest('/orders/7');

    stagedPipeline([ResolveContext::class]);

    expect(pipeline()->process(auditData())?->context)
        ->toHaveKeys(['ip', 'url', 'method', 'hostname', 'environment']);
});

it('produces what the engine produces, because it is the engine', function (): void {
    httpRequest('/orders/7');

    stagedPipeline([ResolveContext::class]);

    expect(pipeline()->process(auditData())?->context)->toEqual(contextEngine()(auditData())->context);
});

it('ships in the package order', function (): void {
    expect(Pipeline::DEFAULT_STAGES)->toContain(ResolveContext::class);
});

it('still gives a captured entry its actor and its source', function (): void {
    $actor = ActingUser::query()->create(['name' => 'Ada']);
    test()->actingAs($actor);
    httpRequest('/orders');

    $subject = AuditedSubject::query()->create(['name' => 'first']);

    $audit = auditsOf($subject)->first();

    expect($audit?->actor_id)->toBe((string) $actor->getKey())
        ->and($audit?->source)->toBe(Source::Http);
});
