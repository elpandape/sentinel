<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use Closure;

/**
 * The attribution of the capture the pipeline is running for, held for exactly that long. The
 * stage that resolves context reads it; the recorder sets it around the pass and takes it back on
 * the way out, exception included, so no capture inherits what the one before it named.
 *
 * A stack rather than a slot, because a listener may record from inside a pass, and the entry it
 * records is attributed on its own terms and not on the outer capture's.
 *
 * @internal
 */
final class Attributions
{
    /**
     * @var list<Attribution>
     */
    private array $stack = [];

    public function within(Attribution $attribution, Closure $callback): mixed
    {
        $this->stack[] = $attribution;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    public function current(): ?Attribution
    {
        $last = array_key_last($this->stack);

        return $last === null ? null : $this->stack[$last];
    }
}
