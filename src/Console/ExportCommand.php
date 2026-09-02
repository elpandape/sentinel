<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Compliance\Export;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory;
use Override;

/**
 * Hands the trail to somebody who does not have the database, with what proves it came from here.
 *
 * The filter is the Query API and not a second query language: the same narrowing an application
 * writes in code is what an operator writes on the command line, so there is one thing to learn and
 * one thing to keep correct.
 *
 * In compliance mode this is a read like any other, and leaves the same two records behind. That is
 * the point rather than a side effect: an export is the largest read a trail ever serves.
 */
final class ExportCommand extends Command
{
    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:export
        {--format=ndjson : json, ndjson or csv. csv is lossy and for people, ndjson round-trips}
        {--disk= : Write to this filesystem disk instead of standard output}
        {--path= : Where on the disk to write it}
        {--tenant= : Only this tenant}
        {--type= : Only this audit type}
        {--limit=500 : How many entries at most}';

    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

    public function handle(Export $export, AuditQuery $query, Factory $disks): int
    {
        $format = $this->text('format') ?? 'ndjson';

        if (! in_array($format, Export::FORMATS, true)) {
            $this->warn($this->translated('unknown_format', [
                'format' => $format,
                'accepted' => implode(', ', Export::FORMATS),
            ]));

            return self::INVALID;
        }

        $rendered = $export->render($this->narrowed($query)->get(), $format);

        $disk = $this->text('disk');
        $path = $this->text('path');

        if ($disk !== null && $path !== null) {
            $disks->disk($disk)->put($path, $rendered->body);
            $disks->disk($disk)->put($path.'.manifest.json', (string) json_encode($rendered->manifest(), JSON_PRETTY_PRINT));

            $this->info($this->translated('written', [
                'entries' => $rendered->entries,
                'path' => $path,
                'disk' => $disk,
                'digest' => $rendered->digest,
            ]));

            return self::SUCCESS;
        }

        $this->line($rendered->body);
        $this->info($this->translated('rendered', ['entries' => $rendered->entries, 'digest' => $rendered->digest]));

        return self::SUCCESS;
    }

    private function narrowed(AuditQuery $query): AuditQuery
    {
        $tenant = $this->text('tenant');
        $type = $this->text('type');
        $limit = $this->text('limit');

        if ($tenant !== null) {
            $query = $query->forTenant($tenant);
        }

        if ($type !== null) {
            $query = $query->whereType($type);
        }

        return $query->take($limit === null ? 500 : (int) $limit);
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function translated(string $key, array $replace = []): string
    {
        $line = __('sentinel::sentinel.commands.export.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
