<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Partitions\Grammar;
use ElPandaPe\Sentinel\Partitions\Partition;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;

use function Orchestra\Testbench\default_skeleton_path;

require __DIR__.'/../vendor/autoload.php';

/*
 * What the trail costs once it is big. It is not `make test-dbs` and it is not a gate: it writes
 * millions of rows, it needs a data directory on real disk, and it runs against the two bench
 * services in compose.yaml rather than the tmpfs ones the suite uses.
 *
 *   make bench-volume ENGINE=pgsql ROWS=1000000 SHAPE=partitioned
 *
 * The dataset is seeded with raw SQL, deliberately. What is being measured is what an operation
 * costs ON a table of that size, not how long it takes to build one, and going through the package
 * for ten million rows would take hours to produce a number nobody asked for. The entries it plants
 * carry no real chain, which is why nothing here verifies one — the chain is measured by walking it.
 */

$engine = getenv('ENGINE') ?: 'pgsql';
$rows = (int) (getenv('ROWS') ?: 1_000_000);
$shape = getenv('SHAPE') ?: 'flat';
$writes = (int) (getenv('WRITES') ?: 2_000);

$app = Application::create(basePath: default_skeleton_path() ?: null);
$config = $app->make('config');

$config->set('database.default', 'bench');
$config->set('database.connections.bench', $engine === 'mysql' ? [
    'driver' => 'mysql', 'host' => 'mysql-bench', 'port' => 3306, 'database' => 'sentinel',
    'username' => 'root', 'password' => 'secret', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
] : [
    'driver' => 'pgsql', 'host' => 'postgres-bench', 'port' => 5432, 'database' => 'sentinel',
    'username' => 'postgres', 'password' => 'secret', 'charset' => 'utf8', 'prefix' => '',
]);

$config->set('app.key', 'base64:'.base64_encode(str_pad('sentinel-bench-key', 32, '0')));

$app->register(SentinelServiceProvider::class);

/** @var Config $sentinel */
$sentinel = $app->make(Config::class);
$table = $sentinel->table('audits');

echo "engine={$engine} rows={$rows} shape={$shape}\n\n";

// A run starts from nothing: a half-seeded table from a previous attempt would measure itself.
foreach (['audits', 'audit_tags', 'audit_relations', 'transactions', 'checkpoints', 'archives', 'access_log'] as $name) {
    Schema::dropIfExists($sentinel->table($name));
}

Schema::dropIfExists('migrations');

Artisan::call('migrate', ['--force' => true]);

/**
 * Runs one migration file, found by the name it carries after its timestamp. The base Migration
 * class declares no up(), because a migration is free not to have one; every file in this package
 * does.
 */
$migrate = static function (string $directory, string $matching = ''): void {
    $files = glob(__DIR__."/../database/{$directory}/*{$matching}*.php");

    /** @var Migration $loaded */
    $loaded = require is_array($files) && $files !== []
        ? $files[0]
        : throw new RuntimeException("No migration matching [{$matching}] in [{$directory}].");

    /** @phpstan-ignore method.notFound */
    $loaded->up();
};

if ($shape === 'partitioned') {
    // The same order a published stub produces: the flat table goes, the divided one takes its
    // name, and the migrations a stub does not replace are applied on top of it.
    Schema::dropIfExists($table);

    $migrate('stubs/partitioned/'.($engine === 'mysql' ? 'mysql-range' : 'pgsql-range'));
    $migrate('migrations', 'add_occurrence_indexes');

    // The seed spreads over as many months as it has quarter-millions of rows, and a partition it
    // has no month for lands in the catch-all — which would measure the catch-all rather than the
    // division. This is the maintenance command doing exactly what it exists for.
    Artisan::call('sentinel:partitions', ['--ahead' => (string) max(4, (int) ceil($rows / 250_000))]);
}

$timed = static function (string $label, Closure $work): float {
    $start = hrtime(true);
    $work();
    $ms = (hrtime(true) - $start) / 1_000_000;

    printf("%-52s %10.1f ms\n", $label, $ms);

    return $ms;
};

/*
 * The dataset. Both engines can generate it themselves, which keeps a ten-million row seed at a few
 * minutes instead of a few hours, and the shape is the one the indexes were built for: many
 * subjects, many actors, many tenants, and a severity where the interesting value is the rare one.
 */
$seed = static function (int $count) use ($engine, $table, $timed): void {
    $months = max(1, (int) ceil($count / 250_000));

    $timed("seeding {$count} entries", static function () use ($engine, $table, $count, $months): void {
        $columns = 'id, stream, sequence, audit_type, event, severity, subject_type, subject_id,'
            .' actor_type, actor_id, tenant_id, transaction_id, request_id, trace_id, span_id,'
            .' source, version, context, "before", "after", changes, metadata, payload_version,'
            .' algorithm, previous_hash, hash, occurred_at, created_at';

        if ($engine === 'pgsql') {
            DB::statement("insert into {$table} ({$columns})
                select lpad(i::text, 26, '0'), 'global', i,
                    case when i % 60 = 7 then 'transition' else 'model' end,
                    'event.' || (i % 60),
                    case when i % 150 = 0 then 'critical' else 'info' end,
                    'invoice', (i % 10000)::text, 'user', (i % 5000)::text, 'tenant-' || (i % 500),
                    lpad((i % 1000)::text, 26, '0'), 'req-' || i,
                    lpad((i % 10000)::text, 32, '0'), lpad((i % 10000)::text, 16, '0'),
                    case when i % 2 = 0 then 'http' else 'cli' end, i % 10,
                    jsonb_build_object('ip', '10.' || (i % 255) || '.' || ((i / 255) % 255) || '.1',
                        'route', 'invoices.' || (i % 300), 'method', 'GET', 'url', '/invoices/' || i),
                    jsonb_build_object('total', i), jsonb_build_object('total', i + 1),
                    jsonb_build_array(jsonb_build_object('op', 'replace', 'path', '/total')),
                    '{}'::jsonb, 1, 'sha256', repeat('a', 64), repeat('b', 64),
                    date_trunc('month', now()) + ((i % {$months}) || ' months')::interval + ((i % 27) || ' days')::interval,
                    date_trunc('month', now()) + ((i % {$months}) || ' months')::interval + ((i % 27) || ' days')::interval
                from generate_series(1, {$count}) i");

            return;
        }

        DB::statement('drop table if exists bench_numbers');
        DB::statement('create table bench_numbers (i bigint primary key)');
        DB::statement('set session cte_max_recursion_depth = 100000');

        for ($block = 0; $block * 100_000 < $count; $block++) {
            $from = $block * 100_000 + 1;
            $to = min($count, ($block + 1) * 100_000);

            DB::statement("insert into bench_numbers (i) with recursive s(i) as
                (select {$from} union all select i + 1 from s where i < {$to}) select i from s");
        }

        $mysqlColumns = str_replace('"', '`', $columns);

        DB::statement("insert into {$table} ({$mysqlColumns})
            select lpad(i, 26, '0'), 'global', i,
                case when i % 60 = 7 then 'transition' else 'model' end,
                concat('event.', i % 60),
                case when i % 150 = 0 then 'critical' else 'info' end,
                'invoice', i % 10000, 'user', i % 5000, concat('tenant-', i % 500),
                lpad(i % 1000, 26, '0'), concat('req-', i),
                lpad(i % 10000, 32, '0'), lpad(i % 10000, 16, '0'),
                case when i % 2 = 0 then 'http' else 'cli' end, i % 10,
                json_object('ip', concat('10.', i % 255, '.', floor(i / 255) % 255, '.1'),
                    'route', concat('invoices.', i % 300), 'method', 'GET', 'url', concat('/invoices/', i)),
                json_object('total', i), json_object('total', i + 1),
                json_array(json_object('op', 'replace', 'path', '/total')),
                json_object(), 1, 'sha256', repeat('a', 64), repeat('b', 64),
                date_add(date_add(date_format(now(), '%Y-%m-01'), interval (i % {$months}) month), interval (i % 27) day),
                date_add(date_add(date_format(now(), '%Y-%m-01'), interval (i % {$months}) month), interval (i % 27) day)
            from bench_numbers");

        DB::statement('drop table bench_numbers');
    });
};

$analyze = static fn (): bool => DB::statement(match (DB::connection()->getDriverName()) {
    'mysql' => "analyze table {$table}",
    default => "analyze {$table}",
});

$seed($rows);
$timed('analyze', $analyze);

echo "\n-- the write path, on a table of that size --\n";

/** @var DatabaseLedger $ledger */
$ledger = $app->make(DatabaseLedger::class);
$sequence = $rows;

$write = static function (int $times) use ($ledger, &$sequence): void {
    for ($i = 0; $i < $times; $i++) {
        $ledger->write(new ElPandaPe\Sentinel\Data\AuditData(
            audit_type: 'model',
            event: 'created',
            severity: Severity::Info,
            occurred_at: new DateTimeImmutable,
            stream: 'writes',
            subject_type: 'invoice',
            subject_id: (string) ++$sequence,
            context: ['ip' => '203.0.113.7', 'route' => 'invoices.store'],
        ));
    }
};

$write(200);
$plain = $timed("{$writes} writes, no json index", static fn () => $write($writes));

$timed('publishing the json index', static fn () => $migrate('stubs/json-indexes'));
$timed('analyze', $analyze);

$write(200);
$indexed = $timed("{$writes} writes, json index published", static fn () => $write($writes));

printf("%-52s %+9.1f %%\n", 'delta per write', ($indexed - $plain) / $plain * 100);

echo "\n-- what each published filter costs --\n";

$filters = [
    'for()' => static fn (): AuditQuery => Sentinel::audits()->for('invoice', '7'),
    'by()' => static fn (): AuditQuery => Sentinel::audits()->by('user', '7'),
    'whereEvent()' => static fn (): AuditQuery => Sentinel::audits()->whereEvent('event.7'),
    'whereSeverity()' => static fn (): AuditQuery => Sentinel::audits()->whereSeverity(Severity::Critical),
    'forTenant()' => static fn (): AuditQuery => Sentinel::audits()->forTenant('tenant-7'),
    'whereType()' => static fn (): AuditQuery => Sentinel::audits()->whereType('transition'),
    'whereIp()' => static fn (): AuditQuery => Sentinel::audits()->whereIp('10.7.0.1'),
    'whereRoute()' => static fn (): AuditQuery => Sentinel::audits()->whereRoute('invoices.7'),
];

foreach ($filters as $label => $build) {
    $timed($label, static fn () => $build()->take(50)->get());
}

echo "\n-- walking a stream --\n";

$walked = 0;
$timed('LedgerStream over the whole seeded stream', static function () use ($ledger, &$walked): void {
    foreach ($ledger->stream('global') as $ignored) {
        $walked++;
    }
});
echo "  ({$walked} entries)\n";

echo "\n-- retiring a range --\n";

if ($shape === 'partitioned') {
    $grammar = new Grammar;
    $driver = DB::connection()->getDriverName();
    $name = $grammar->prefix($driver, $table).'p'.CarbonImmutable::now()->format('Y_m');
    $held = DB::table($table)->count();

    $timed('dropping one partition', static fn () => DB::statement(
        $grammar->retire($driver, $table, Partition::named($name)),
    ));

    printf("%-52s %10d rows\n", 'entries it took with it', $held - DB::table($table)->count());
} else {
    // The same month a partition drop would have taken, so the two numbers are about the same range.
    $from = CarbonImmutable::now()->startOfMonth();
    $held = DB::table($table)->count();

    $timed('deleting the same range row by row', static fn () => DB::table($table)
        ->whereBetween('created_at', [$from->format('Y-m-d H:i:s'), $from->addMonth()->format('Y-m-d H:i:s')])
        ->delete());

    printf("%-52s %10d rows\n", 'entries it took with it', $held - DB::table($table)->count());
}

echo "\ndone.\n";
