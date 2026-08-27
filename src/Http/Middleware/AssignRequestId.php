<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Http\Middleware;

use Closure;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in, registered in no group. An application that puts it in a stack gets every entry of
 * one request under one identifier, and a client that already correlates gets its own back.
 */
final readonly class AssignRequestId
{
    private const int MAX_LENGTH = 64;

    public function __construct(private Runtime $runtime, private Config $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $this->config->requestIdHeader();
        $id = $this->incoming($request->headers->get($header)) ?? Str::ulid()->toString();

        $this->runtime->assignRequestId($id);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set($header, $id);

        return $response;
    }

    private function incoming(?string $id): ?string
    {
        return $id !== null
            && strlen($id) <= self::MAX_LENGTH
            && preg_match('/^[\x21-\x7e]+$/', $id) === 1
            ? $id
            : null;
    }
}
