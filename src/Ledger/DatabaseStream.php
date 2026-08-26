<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Models\Audit;
use Traversable;

final readonly class DatabaseStream implements LedgerStream
{
    public function __construct(
        private Audit $model,
        private string $name,
        private int $from = 1,
        private ?int $to = null,
        private int $chunk = 500,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function range(int $from, ?int $to = null): static
    {
        return new self($this->model, $this->name, $from, $to, $this->chunk);
    }

    public function getIterator(): Traversable
    {
        $cursor = $this->from - 1;

        do {
            $query = $this->model->newQuery()
                ->where('stream', $this->name)
                ->where('sequence', '>', $cursor);

            if ($this->to !== null) {
                $query->where('sequence', '<=', $this->to);
            }

            $page = $query->orderBy('sequence')->limit($this->chunk)->get();

            foreach ($page as $audit) {
                $cursor = $audit->sequence;

                yield $audit;
            }
        } while ($page->count() === $this->chunk);
    }
}
