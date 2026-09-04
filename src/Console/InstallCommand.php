<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Puts the configuration where an application can edit it, and says what is still missing.
 *
 * It publishes one thing and one thing only. The migrations do not need publishing — the provider
 * loads the package's own until an application takes a file over — and taking eight files over is
 * a decision whose consequences outlive this command, so it is named with its tag rather than done
 * on somebody's behalf. The same goes for the index behind two of the filters and for the three
 * partitioned alternatives: each is a cost somebody chooses, not a default.
 *
 * Running it twice is the ordinary case rather than the careless one, because asking is how you
 * find out what an installation is missing. Nothing here overwrites: a configuration already in
 * place is left exactly as it was, edits and all, and reported as present.
 */
final class InstallCommand extends Command
{
    use Translates;

    /**
     * @var list<string>
     */
    public const array OPTIONAL = [
        'sentinel-lang',
        'sentinel-migrations',
        'sentinel-json-indexes',
        'sentinel-partitioned-pgsql-range',
        'sentinel-partitioned-pgsql-tenant',
        'sentinel-partitioned-mysql-range',
    ];

    /**
     * @var list<string>
     */
    public const array TABLES = [
        'audits',
        'audit_tags',
        'audit_relations',
        'transactions',
        'checkpoints',
        'archives',
        'access_log',
    ];

    protected $signature = 'sentinel:install';

    public function handle(Config $config, DatabaseManager $databases, Filesystem $files): int
    {
        $this->info($this->publish($files) ? $this->translated('published') : $this->translated('configured'));

        try {
            $missing = $this->missing($config, $databases);
        } catch (Throwable $failure) {
            $this->error($this->translated('unreadable', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        $this->report($missing);
        $this->line($this->translated('optional', ['tags' => implode(', ', self::OPTIONAL)]));

        return self::SUCCESS;
    }

    /**
     * Whether this run is what put the file there.
     *
     * The copy is made here rather than handed to vendor:publish, which would need --force to
     * write and would then write over an edited file. Not overwriting is the whole promise of
     * running this twice, and a flag that has to be withheld for the promise to hold is a promise
     * one keystroke from being broken.
     */
    private function publish(Filesystem $files): bool
    {
        $destination = $this->laravel->configPath('sentinel.php');

        if ($files->exists($destination)) {
            return false;
        }

        $files->ensureDirectoryExists(dirname($destination));
        $files->copy(__DIR__.'/../../config/sentinel.php', $destination);

        return true;
    }

    /**
     * @return list<string>
     */
    private function missing(Config $config, DatabaseManager $databases): array
    {
        $schema = $databases->connection($config->connection())->getSchemaBuilder();

        return array_values(array_filter(
            array_map($config->table(...), self::TABLES),
            static fn (string $table): bool => ! $schema->hasTable($table),
        ));
    }

    /**
     * A missing table is not a failure of this command. It is the ordinary state between
     * publishing and migrating, so it is reported and the exit code stays zero — what it must not
     * do is go quiet about it.
     *
     * @param  list<string>  $missing
     */
    private function report(array $missing): void
    {
        if ($missing === []) {
            $this->info($this->translated('migrated', ['tables' => count(self::TABLES)]));

            return;
        }

        $this->warn($this->translated('unmigrated', [
            'missing' => count($missing),
            'tables' => count(self::TABLES),
            'names' => implode(', ', $missing),
        ]));

        $this->line($this->translated('migrate'));
    }
}
