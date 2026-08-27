<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Identity;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Auth\Factory;

/**
 * The impersonator is a person impersonating a person, so it carries the class the guard
 * authenticates — Guard has no getProvider() to hydrate it any other way. An id equal to
 * the actor's is not impersonation: it is the same session.
 */
final readonly class ImpersonatorResolver implements Resolver
{
    public function __construct(
        private Runtime $runtime,
        private Factory $auth,
        private Config $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $request = $this->runtime->request();

        if (! $request instanceof \Illuminate\Http\Request || ! $request->hasSession()) {
            return [];
        }

        $id = $request->session()->get($this->config->impersonatorSessionKey());

        if (! is_string($id) && ! is_int($id)) {
            return [];
        }

        $actor = $this->auth->guard($this->config->actorGuard())->user();

        if ($actor === null || (string) $id === Identity::id($actor)) {
            return [];
        }

        return [
            'impersonator_type' => Identity::type($actor),
            'impersonator_id' => (string) $id,
        ];
    }
}
