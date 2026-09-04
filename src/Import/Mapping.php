<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

use ElPandaPe\Sentinel\Data\AuditData;

/**
 * What one source row turned into, or why it turned into nothing.
 *
 * A row an origin cannot read is not an error and does not stop an import: the other package's
 * history is what it is, and one row with no timestamp on it among a million is a fact to report
 * rather than a reason to abandon the other 999,999. The reason travels with the refusal so the
 * report can group by it, which is what tells an operator whether they hit one odd row or a whole
 * column they did not know was empty.
 */
final readonly class Mapping
{
    private function __construct(
        public ?AuditData $data,
        public ?string $refused,
    ) {}

    public static function of(AuditData $data): self
    {
        return new self($data, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }
}
