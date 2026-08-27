<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\RequestResolver;
use Illuminate\Support\Facades\Route;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\runtime;

it('resolves nothing outside a request', function (): void {
    expect(app(RequestResolver::class)->resolve())->toBeEmpty();
});

it('describes the request the entry was captured in', function (): void {
    httpRequest('/invoices/500', ['User-Agent' => 'Sentinel/1.0']);

    $resolved = app(RequestResolver::class)->resolve();

    expect($resolved['method'])->toBe('GET')
        ->and($resolved['user_agent'])->toBe('Sentinel/1.0')
        ->and($resolved['url'])->toContain('/invoices/500')
        ->and($resolved['route'])->toBeNull()
        ->and($resolved['request_id'])->toBeString();
});

it('names the route when the request matched one', function (): void {
    Route::get('/invoices/{invoice}', fn (): string => 'ok')->name('invoices.show');

    $this->get('/invoices/500');

    expect(app(RequestResolver::class)->resolve()['route'])->toBe('invoices.show');
});

it('prefers the identifier the middleware assigned', function (): void {
    httpRequest('/invoices/500');
    runtime()->assignRequestId('01j0000000000000000000000');

    expect(app(RequestResolver::class)->resolve()['request_id'])->toBe('01j0000000000000000000000');
});
