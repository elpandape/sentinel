<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

/**
 * The migrations the package still has to load, which are the ones the application has not
 * taken over. The decision is per file: a package that ships more than one migration and
 * decides all-or-nothing on the first of them stops delivering every migration it publishes
 * afterwards, to precisely the installations that have the most data.
 */
final readonly class PackageMigrations
{
    public function __construct(private string $directory, private string $published) {}

    /**
     * @return list<string>
     */
    public function unpublished(): array
    {
        $files = glob("{$this->directory}/*_*.php");

        return array_values(array_filter(
            is_array($files) ? $files : [],
            fn (string $file): bool => ! new PublishedMigration($this->published, $this->name($file))->exists(),
        ));
    }

    private function name(string $file): string
    {
        $name = basename($file, '.php');

        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name) ?? $name;
    }
}
