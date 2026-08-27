<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Identity;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\Guard;
use InvalidArgumentException;

final readonly class ActorResolver implements Resolver
{
    public function __construct(private Factory $auth, private Config $config) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $user = $this->guard()->user();

        if ($user === null) {
            return [];
        }

        $id = Identity::id($user);

        return $id === null ? [] : [
            'actor_type' => Identity::type($user),
            'actor_id' => $id,
        ];
    }

    private function guard(): Guard
    {
        $name = $this->config->actorGuard();

        try {
            return $this->auth->guard($name);
        } catch (InvalidArgumentException $exception) {
            throw ConfigurationException::unknown('resolvers.actor.guard', $name ?? 'null', $exception->getMessage());
        }
    }
}
