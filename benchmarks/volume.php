<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
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
$labels = $sentinel->table('audit_tags');
$lines = $sentinel->table('audit_relations');

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
 *
 * Four things the seed used to hide, and what each of them cost:
 *
 *  - The address had 255 x 255 possible values, so one of them matched 154 rows out of ten million
 *    and whereIp() measured a filter with almost nothing behind it. It now spreads over four
 *    hundred, which puts it beside the tenant and the route rather than two orders under them.
 *  - occurred_at and created_at were the same expression, month modulo plus day modulo: 1 080
 *    distinct instants for ten million rows, none of them ordered with the identifier. Every read
 *    orders by that column. It is now strictly increasing with the row number and spread evenly
 *    over the months the partitions cover.
 *  - capture_id was left null, so the unique index over it was measured empty.
 *  - The labels and the relations tables were created and never written to, which is why four
 *    published filters had no row in the table this file prints.
 */
$seed = static function (int $count) use ($engine, $table, $timed): void {
    $months = max(1, (int) ceil($count / 250_000));

    // One microsecond step per row across the whole seeded span, so the clock is distinct per row
    // and ordered with the identifier — which is what the composite indexes are built on.
    $step = max(1, intdiv($months * 30 * 86_400 * 1_000_000, max(1, $count)));

    $timed("seeding {$count} entries", static function () use ($engine, $table, $count, $step): void {
        $columns = 'id, stream, sequence, audit_type, event, severity, subject_type, subject_id,'
            .' actor_type, actor_id, tenant_id, transaction_id, request_id, trace_id, span_id,'
            .' source, version, context, "before", "after", changes, metadata, payload_version,'
            .' algorithm, previous_hash, hash, capture_id, occurred_at, created_at';

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
                    jsonb_build_object('ip', '10.0.' || ((i % 400) / 256) || '.' || ((i % 400) % 256),
                        'route', 'invoices.' || (i % 300), 'method', 'GET', 'url', '/invoices/' || i),
                    jsonb_build_object('total', i), jsonb_build_object('total', i + 1),
                    jsonb_build_array(jsonb_build_object('op', 'replace', 'path', '/total')),
                    '{}'::jsonb, 1, 'sha256', repeat('a', 64), repeat('b', 64),
                    'C' || lpad(i::text, 25, '0'),
                    date_trunc('month', now()) + ((i::bigint * {$step}) || ' microseconds')::interval,
                    date_trunc('month', now()) + ((i::bigint * {$step}) || ' microseconds')::interval
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
                json_object('ip', concat('10.0.', floor((i % 400) / 256), '.', (i % 400) % 256),
                    'route', concat('invoices.', i % 300), 'method', 'GET', 'url', concat('/invoices/', i)),
                json_object('total', i), json_object('total', i + 1),
                json_array(json_object('op', 'replace', 'path', '/total')),
                json_object(), 1, 'sha256', repeat('a', 64), repeat('b', 64),
                concat('C', lpad(i, 25, '0')),
                date_add(date_format(now(), '%Y-%m-01'), interval (i * {$step}) microsecond),
                date_add(date_format(now(), '%Y-%m-01'), interval (i * {$step}) microsecond)
            from bench_numbers");

        DB::statement('drop table bench_numbers');
    });
};

/*
 * The labels and the relation lines, over one entry in a hundred. Four published filters read these
 * two tables and neither was ever written to, so four rows of the table this file prints could not
 * be measured at all — the filters answered nothing, quickly.
 *
 * One in a hundred is what keeps a label selective. Both planners are cost-based and a label carried
 * by a large share of the table is walked rather than sought, which is the right plan for a label
 * that broad and the wrong shape for measuring the index.
 */
$seedProjections = static function () use ($engine, $table, $labels, $lines, $timed): void {
    $concat = static fn (string $prefix, string $of): string => $engine === 'mysql'
        ? "concat('{$prefix}', {$of})"
        : "'{$prefix}' || ({$of})";

    $timed('seeding labels and relation lines', static function () use ($table, $labels, $lines, $concat): void {
        DB::statement("insert into {$labels} (audit_id, tag)
            select id, ".$concat('label.', '(sequence / 100) % 20')."
            from {$table} where sequence % 100 = 0");

        DB::statement("insert into {$labels} (audit_id, tag)
            select id, ".$concat('label.', '((sequence / 100) % 20 + 1) % 20')."
            from {$table} where sequence % 100 = 0");

        DB::statement("insert into {$lines} (audit_id, relation, operation, related_type, related_id)
            select id, ".$concat('relation.', '(sequence / 100) % 10').",
                case when sequence % 200 = 0 then 'attach' else 'detach' end,
                'Label', ".$concat('', 'sequence % 5000')."
            from {$table} where sequence % 100 = 0");
    });
};

/**
 * The statement the driver actually issued for a read, captured as it ran rather than rebuilt. What
 * gets explained has to be what the engine was asked, or the plan describes a query nobody made.
 *
 * It is the read of the trail itself and not the eager load of the labels hanging off it.
 *
 * @return array{string, list<mixed>}
 */
$statement = static function (Closure $read) use ($table): array {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $read();

    DB::disableQueryLog();

    foreach (DB::getQueryLog() as $query) {
        if (str_contains((string) $query['query'], $table)) {
            /** @var list<mixed> $bindings */
            $bindings = $query['bindings'];

            return [(string) $query['query'], $bindings];
        }
    }

    return ['', []];
};

/**
 * The plan, on one line, in the engine's own words. PostgreSQL says `Seq Scan`; MySQL says
 * `Table scan`, but only under FORMAT=TREE — the tabular EXPLAIN spells the same thing `type: ALL`,
 * and one vocabulary for two engines is what makes the assertion below readable.
 *
 * @param  list<mixed>  $bindings
 */
$explain = static function (string $sql, array $bindings): string {
    if ($sql === '') {
        return '(not captured)';
    }

    $driver = DB::connection()->getDriverName();
    $rows = DB::select(($driver === 'mysql' ? 'explain format=tree ' : 'explain ').$sql, $bindings);

    $lines = [];

    foreach ($rows as $row) {
        $columns = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', (array) $row);

        $lines[] = trim(implode(' ', $columns));
    }

    return preg_replace('/\s+/', ' ', implode(' | ', $lines)) ?? '';
};

/**
 * How many entries the filter matched, which is the number that explains the clock beside it: a
 * filter that reaches its index and then sorts a third of the table is slow for a reason that has
 * nothing to do with the index.
 *
 * The prefix is dropped rather than the query rebuilt, so what is counted is what was read.
 *
 * @param  list<mixed>  $bindings
 */
$matched = static function (string $sql, array $bindings): string {
    $unbounded = preg_replace('/\s+limit\s+\d+\s*$/i', '', $sql);

    if ($unbounded === null || $unbounded === $sql) {
        return '-';
    }

    $counted = DB::selectOne("select count(*) as matched from ({$unbounded}) as bounded", $bindings);
    $rows = is_object($counted) ? ($counted->matched ?? 0) : 0;

    return number_format(is_numeric($rows) ? (float) $rows : 0.0);
};

/**
 * Whether a plan walks the table instead of seeking into it, in either engine's spelling.
 */
$scans = static fn (string $plan): bool => str_contains($plan, 'Seq Scan') || str_contains($plan, 'Table scan');

$analyze = static fn (): bool => DB::statement(match (DB::connection()->getDriverName()) {
    'mysql' => "analyze table {$table}",
    default => "analyze {$table}",
});

$seed($rows);
$seedProjections();
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

/*
 * Every published filter, measured the same way: warmed, then five passes, reported as the median
 * and the spread, with the rows it matched and the plan the engine chose printed beside it.
 *
 * A single cold pass was what this used to report, which is why eight numbers went into the README
 * under a heading that says every published filter. There were eighteen, and four of them could not
 * be measured at all because nothing seeded the tables they read.
 *
 * The pass FAILS on a filter the README does not call a refiner whose plan walks the table. That is
 * the deuda v0.20.0 left open: the README claims no published filter falls back to a full pass
 * without being called a refiner, and until now nothing at volume checked the claim.
 */
$cursor = str_pad((string) intdiv($rows, 2), 26, '0', STR_PAD_LEFT);

/** @var list<array{string, Closure(): AuditQuery, ?bool}> $filters */
$filters = [
    ['for()', static fn (): AuditQuery => Sentinel::audits()->for('invoice', '7'), true],
    ['by()', static fn (): AuditQuery => Sentinel::audits()->by('user', '7'), true],
    ['whereEvent()', static fn (): AuditQuery => Sentinel::audits()->whereEvent('event.7'), true],
    ['whereType()', static fn (): AuditQuery => Sentinel::audits()->whereType('transition'), true],
    ['whereSeverity()', static fn (): AuditQuery => Sentinel::audits()->whereSeverity(Severity::Critical), true],
    ['forTenant()', static fn (): AuditQuery => Sentinel::audits()->forTenant('tenant-7'), true],
    ['inTransaction()', static fn (): AuditQuery => Sentinel::audits()->inTransaction(str_pad('7', 26, '0', STR_PAD_LEFT)), true],
    ['withTrace()', static fn (): AuditQuery => Sentinel::audits()->withTrace(str_pad('7', 32, '0', STR_PAD_LEFT)), true],
    ['whereTag()', static fn (): AuditQuery => Sentinel::audits()->whereTag('label.7'), true],
    ['whereAnyTag()', static fn (): AuditQuery => Sentinel::audits()->whereAnyTag(['label.7', 'label.8']), true],
    ['whereIp()', static fn (): AuditQuery => Sentinel::audits()->whereIp('10.0.0.7'), true],
    ['whereRoute()', static fn (): AuditQuery => Sentinel::audits()->whereRoute('invoices.7'), true],

    // The four the README calls refiners: they narrow a result, they do not find one, and each of
    // them alone walks the table on purpose.
    ['whereSource()', static fn (): AuditQuery => Sentinel::audits()->whereSource(Source::Cli), false],
    ['between()', static fn (): AuditQuery => Sentinel::audits()->between(
        CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->startOfMonth()->addDays(3),
    ), false],
    ['whereFieldChanged()', static fn (): AuditQuery => Sentinel::audits()->whereFieldChanged('total'), false],
    ['whereVersion()', static fn (): AuditQuery => Sentinel::audits()->whereVersion(7), false],

    // The four the README's filter table does not classify at all. Their plan is printed and not
    // asserted: which of them find and which refine is v0.22.3's row to write, not this file's to
    // decide by failing.
    ['whereRelation()', static fn (): AuditQuery => Sentinel::audits()->whereRelation('relation.7'), null],
    ['whereRelated()', static fn (): AuditQuery => Sentinel::audits()->whereRelated('Label', '700'), null],
    ['whereOperation()', static fn (): AuditQuery => Sentinel::audits()->whereOperation(RelationOperation::Attach), null],
    ['after()', static fn (): AuditQuery => Sentinel::audits()->after($cursor), null],
];

printf("%-22s %10s %10s %10s %12s  %s\n", 'filter', 'median', 'low', 'high', 'rows', 'plan');

$walking = [];

foreach ($filters as [$label, $build, $finds]) {
    $read = static fn () => $build()->take(50)->get();

    $read();

    $times = [];

    for ($pass = 0; $pass < 5; $pass++) {
        $start = hrtime(true);
        $read();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($times);

    [$sql, $bindings] = $statement($read);
    $plan = $explain($sql, $bindings);

    printf(
        "%-22s %8.1f ms %8.1f %8.1f %12s  %s\n",
        $label,
        $times[2],
        $times[0],
        $times[4],
        $matched($sql, $bindings),
        $plan,
    );

    if ($finds === true && $scans($plan)) {
        $walking[] = $label;
    }
}

if ($walking !== []) {
    echo "\nFAILED: the plan walks the table for ", implode(', ', $walking),
    ", and the README does not call any of them a refiner.\n";

    exit(1);
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
