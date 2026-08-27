<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\contextEngine;
use function ElPandaPe\Sentinel\Tests\httpRequest;

it('gives back the same instance until the scope is forgotten', function (string $binding): void {
    $before = app($binding);

    expect(app($binding))->toBe($before);

    app()->forgetScopedInstances();

    expect(app($binding))->not->toBe($before);
})->with([Runtime::class, ExecutionContext::class, ContextEngine::class]);

it('does not leak an actor from one request into the next', function (): void {
    httpRequest('/invoices');
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);

    expect(contextEngine()(auditData())->actor_id)->toBe((string) $user->getKey());

    app()->forgetScopedInstances();
    auth()->forgetGuards();

    expect(contextEngine()(auditData())->actor_id)->toBeNull();
});

it('does not leak a memoized request into the next one', function (): void {
    httpRequest('/first');
    $before = contextEngine()(auditData());

    app()->forgetScopedInstances();
    httpRequest('/second');
    $after = contextEngine()(auditData());

    expect($after->context['url'])->not->toBe($before->context['url'])
        ->and($after->request_id)->not->toBe($before->request_id);
});
