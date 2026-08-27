<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function ElPandaPe\Sentinel\Tests\runtime;

beforeEach(function (): void {
    $this->through = fn (Request $request): Response => app(AssignRequestId::class)
        ->handle($request, static fn (): Response => new Response('ok'));
});

it('generates an identifier and returns it to the client', function (): void {
    $response = ($this->through)(Request::create('/invoices'));

    expect(runtime()->requestId())->toBeString()
        ->and($response->headers->get('X-Request-Id'))->toBe(runtime()->requestId());
});

it('respects an identifier the client already carried', function (): void {
    $request = Request::create('/invoices');
    $request->headers->set('X-Request-Id', 'edge-42');

    expect(($this->through)($request)->headers->get('X-Request-Id'))->toBe('edge-42')
        ->and(runtime()->requestId())->toBe('edge-42');
});

it('reads the header the configuration names', function (): void {
    config()->set('sentinel.resolvers.request.header', 'X-Correlation-Id');

    $request = Request::create('/invoices');
    $request->headers->set('X-Correlation-Id', 'edge-42');

    expect(($this->through)($request)->headers->get('X-Correlation-Id'))->toBe('edge-42');
});

it('ignores an incoming identifier longer than the column', function (): void {
    $request = Request::create('/invoices');
    $request->headers->set('X-Request-Id', str_repeat('a', 65));

    expect(($this->through)($request)->headers->get('X-Request-Id'))->not->toBe(str_repeat('a', 65));
});

it('ignores an incoming identifier that is not printable', function (): void {
    $request = Request::create('/invoices');
    $request->headers->set('X-Request-Id', "edge\n42");

    expect(($this->through)($request)->headers->get('X-Request-Id'))->not->toBe("edge\n42");
});

it('ignores an empty incoming identifier', function (): void {
    $request = Request::create('/invoices');
    $request->headers->set('X-Request-Id', '');

    expect(($this->through)($request)->headers->get('X-Request-Id'))->not->toBe('');
});

it('leaves the identifier stable through the whole request', function (): void {
    $request = Request::create('/invoices');

    $first = ($this->through)($request)->headers->get('X-Request-Id');

    expect(runtime()->requestId())->toBe($first);
});
