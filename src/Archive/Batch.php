<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Enums\BatchLine;

/**
 * The lines of a batch, read back. It is the one place that knows a batch is newline-separated JSON
 * and that a line names its own kind, so the writer proving what it wrote and the reader checking
 * what it found walk the same bytes the same way.
 *
 * A line that will not decode cannot reach here from a file whose checksum passed — the digest is
 * over the exact bytes — so it is treated as what it is: a line this build cannot read, skipped
 * rather than dressed up as a defect the checksum has already ruled out.
 */
final readonly class Batch
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public static function entriesIn(string $body): iterable
    {
        return self::linesOf($body, BatchLine::Entry);
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private static function linesOf(string $body, BatchLine $kind): iterable
    {
        foreach (explode("\n", trim($body)) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['kind'] ?? null) === $kind->value) {
                /** @var array<string, mixed> $decoded */
                yield $decoded;
            }
        }
    }
}
