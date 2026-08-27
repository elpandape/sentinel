<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

/**
 * A JSON Patch operation carries the new value and nothing else. An audit needs the old
 * one too, so it travels here — and when a patch is all the caller had, `oldKnown` says
 * the value is missing instead of letting null pretend to be it.
 *
 * `op` is one of Diff::OPERATIONS.
 */
final readonly class Change
{
    public function __construct(
        public string $path,
        public string $op,
        public mixed $old = null,
        public mixed $new = null,
        public bool $oldKnown = true,
    ) {}

    /**
     * @return array{path: string, op: string, old?: mixed, new: mixed}
     */
    public function toArray(): array
    {
        return $this->oldKnown
            ? ['path' => $this->path, 'op' => $this->op, 'old' => $this->old, 'new' => $this->new]
            : ['path' => $this->path, 'op' => $this->op, 'new' => $this->new];
    }
}
