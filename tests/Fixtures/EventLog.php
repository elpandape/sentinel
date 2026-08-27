<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * Only the events the package dispatches. An eloquent event carries the model itself and
 * therefore the plaintext — that is the application's own object, not something Sentinel
 * built, and the guarantee here is about what Sentinel hands out.
 *
 * Kept outside the execution context on purpose: anything put there is merged into the
 * entry, so a log of dispatched events would end up auditing itself.
 */
final class EventLog
{
    private const string PACKAGE = 'ElPandaPe\\Sentinel\\';

    private const string FIXTURES = 'ElPandaPe\\Sentinel\\Tests\\';

    /**
     * @var list<object>
     */
    private array $events = [];

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function record(string $name, array $payload): void
    {
        foreach ($payload as $event) {
            if (is_object($event) && $this->belongsToThePackage($event::class)) {
                $this->events[] = $event;
            }
        }
    }

    public function contents(): string
    {
        return print_r($this->events, true);
    }

    public function count(): int
    {
        return count($this->events);
    }

    private function belongsToThePackage(string $class): bool
    {
        return str_starts_with($class, self::PACKAGE) && ! str_starts_with($class, self::FIXTURES);
    }
}
