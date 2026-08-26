<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

final readonly class PublishedMigration
{
    public function __construct(private string $directory, private string $name) {}

    public function exists(): bool
    {
        $matches = glob("{$this->directory}/*_{$this->name}.php");

        return is_array($matches) && $matches !== [];
    }
}
