<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console\Concerns;

use ElPandaPe\Sentinel\Support\Reference;

/**
 * Turning what somebody typed into something the package can use, once.
 *
 * Five commands carried their own copy of the string reader and two carried the number reader,
 * and the two number readers did not agree: one asked whether the text was a number, the other
 * cast whatever arrived. Under the second, `--from=yesterday` is sequence zero and the command
 * answers a question nobody asked. Asking is the rule that survives.
 */
trait ReadsOptions
{
    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function number(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * A reference as an operator writes one: `type:id`. The type is taken as written rather than
     * resolved to a class, because a morph map is what decides what a type means here and a
     * command is not entitled to guess past it. The split is the last colon, so an identifier is
     * free to contain one.
     */
    private function reference(string $value): ?Reference
    {
        $split = strrpos($value, ':');

        return in_array($split, [false, 0, strlen($value) - 1], true)
            ? null
            : new Reference(substr($value, 0, $split), substr($value, $split + 1));
    }
}
