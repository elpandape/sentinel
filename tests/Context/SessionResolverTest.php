<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\SessionResolver;

use function ElPandaPe\Sentinel\Tests\httpRequest;

it('resolves nothing outside a request', function (): void {
    expect(app(SessionResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing without a session', function (): void {
    httpRequest('/invoices');

    expect(app(SessionResolver::class)->resolve())->toBeEmpty();
});

it('carries the session the request is bound to', function (): void {
    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));

    expect(app(SessionResolver::class)->resolve()['session_id'])
        ->toBe(app('session.store')->getId());
});
