<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Redacts sensitive arguments here rather than waiting for the general redaction of
 * v0.7.0: an `artisan user:password --password=...` would otherwise reach the ledger in
 * the release before the one that redacts.
 */
final readonly class CommandResolver implements Resolver
{
    private const int MASK_REPEAT = 8;

    public function __construct(
        private Runtime $runtime,
        private Config $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $command = $this->runtime->command();

        if ($command === null) {
            return [];
        }

        return [
            'command' => $command,
            'arguments' => $this->arguments(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(): array
    {
        $needles = $this->config->commandRedactions();
        $mask = str_repeat($this->config->redactionMask(), self::MASK_REPEAT);
        $arguments = [];

        foreach ($this->runtime->arguments() as $key => $value) {
            $key = (string) $key;

            if ($key === 'command') {
                continue;
            }

            if ($this->isSensitive($key, $needles)) {
                $arguments[$key] = $mask;

                continue;
            }

            if (is_scalar($value) || $value === null || is_array($value)) {
                $arguments[$key] = $value;
            }
        }

        return $arguments;
    }

    /**
     * @param  list<string>  $needles
     */
    private function isSensitive(string $key, array $needles): bool
    {
        return array_any($needles, static fn (string $needle): bool => stripos($key, $needle) !== false);
    }
}
