<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;

final readonly class SessionResolver implements Resolver
{
    public function __construct(private Runtime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $request = $this->runtime->request();

        if (! $request instanceof \Illuminate\Http\Request || ! $request->hasSession()) {
            return [];
        }

        return ['session_id' => $request->session()->getId()];
    }
}
