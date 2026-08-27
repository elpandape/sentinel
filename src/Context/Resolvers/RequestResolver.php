<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final readonly class RequestResolver implements Resolver
{
    public function __construct(private Runtime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $request = $this->runtime->request();

        if (! $request instanceof Request) {
            return [];
        }

        return [
            'request_id' => $this->runtime->requestId() ?? Str::ulid()->toString(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'route' => $this->route($request),
            'method' => $request->method(),
        ];
    }

    private function route(Request $request): ?string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        return $route->getName() ?? $route->uri();
    }
}
