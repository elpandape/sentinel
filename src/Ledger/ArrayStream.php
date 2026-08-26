<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Models\Audit;
use Traversable;

final readonly class ArrayStream implements LedgerStream
{
    /**
     * @param  list<Audit>  $audits
     */
    public function __construct(
        private string $name,
        private array $audits,
        private int $from = 1,
        private ?int $to = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function range(int $from, ?int $to = null): static
    {
        return new self($this->name, $this->audits, $from, $to);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->audits as $audit) {
            if ($audit->sequence >= $this->from && ($this->to === null || $audit->sequence <= $this->to)) {
                yield $audit;
            }
        }
    }
}
