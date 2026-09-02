<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

/**
 * Configuration and usage errors, so the messages stay in plain English the way Laravel's own do.
 */
final class ComplianceException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(
            'Sentinel is in compliance mode with ['.implode(', ', $missing).'] switched off. '
            .'Compliance mode is a claim about what the trail can prove, and it cannot prove it '
            .'without them. Turn them on, or turn compliance off.',
        );
    }

    public static function unattributed(): self
    {
        return new self(
            'Sentinel is in compliance mode, where a redaction has to name who ordered it. '
            .'Pass an actor to Redactor::redact(), or use sentinel:redact with --actor.',
        );
    }

    public static function unarchived(string $stream, int $from, int $to): self
    {
        return new self(
            'Sentinel is in compliance mode, where a range is deleted only after it has been archived. '
            ."Sequences {$from}-{$to} of stream {$stream} have no archive batch, so nothing was removed. "
            .'Run the prune with --action=archive, or turn compliance off.',
        );
    }
}
