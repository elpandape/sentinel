<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Benchmarks\BenchAudited;
use ElPandaPe\Sentinel\Benchmarks\BenchPlain;
use ElPandaPe\Sentinel\Benchmarks\BenchProtected;
use ElPandaPe\Sentinel\Benchmarks\BenchSnapshotless;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;

use function Orchestra\Testbench\default_skeleton_path;

require __DIR__.'/../vendor/autoload.php';

const WARMUP = 200;
const ITERATIONS = 2000;

$database = sys_get_temp_dir().'/sentinel-bench.sqlite';
@unlink($database);
touch($database);

$app = Application::create(basePath: default_skeleton_path() ?: null);

// The resolving callback runs before the container holds a config repository.
$app->make('config')->set('database.default', 'bench');
$app->make('config')->set('database.connections.bench', [
    'driver' => 'sqlite',
    'database' => $database,
    'prefix' => '',
    'foreign_key_constraints' => false,
]);

// Encryption derives its default key from this one, the way an application does.
$app->make('config')->set('app.key', 'base64:'.base64_encode(str_pad('sentinel-bench-key', 32, '0')));

$app->register(SentinelServiceProvider::class);

// The baseline measures what the package costs, not what a container's fsync costs.
DB::statement('pragma synchronous = off');
DB::statement('pragma journal_mode = memory');

Artisan::call('migrate', ['--force' => true]);

Schema::create('bench_subjects', static function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('email');
    $table->string('role');
    $table->unsignedInteger('score');
    $table->boolean('active');
});

/**
 * @param  class-string<Model>  $model
 */
$run = static function (string $model, int $times, int $offset): float {
    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $n = $offset + $i;

        $model::query()->create([  // @phpstan-ignore-line staticMethod.dynamicName
            'name' => 'subject-'.$n,
            'email' => 'subject-'.$n.'@example.com',
            'role' => $n % 2 === 0 ? 'admin' : 'editor',
            'score' => $n % 100,
            'active' => $n % 3 !== 0,
        ]);
    }

    return (hrtime(true) - $start) / 1_000_000;
};

$variants = [
    'plain (not audited)' => BenchPlain::class,
    'audited, snapshots on' => BenchAudited::class,
    'audited, snapshots off' => BenchSnapshotless::class,
    'audited, two fields protected' => BenchProtected::class,
];

$offset = 0;
$results = [];

foreach ($variants as $label => $model) {
    $run($model, WARMUP, $offset);
    $offset += WARMUP;

    $results[$label] = $run($model, ITERATIONS, $offset);
    $offset += ITERATIONS;
}

// A second destination, taking the entry the first one sealed. Against a null destination
// the row is the fanout machinery on its own; against a memory one it also carries a
// destination that actually keeps what it is handed.
foreach (['null' => 'a null destination', 'memory' => 'a memory destination'] as $secondary => $description) {
    $app->make('config')->set('sentinel.ledger.default', 'fanout');
    $app->make('config')->set('sentinel.ledger.ledgers.fanout.destinations', ['database', $secondary]);
    $app->forgetScopedInstances();

    $run(BenchAudited::class, WARMUP, $offset);
    $offset += WARMUP;

    $results['audited, fanout to '.$description] = $run(BenchAudited::class, ITERATIONS, $offset);
    $offset += ITERATIONS;
}

// The null ledger canonicalizes and hashes without touching the table, which is what
// separates the cost of the chain from the cost of the write it lands in.
$app->make('config')->set('sentinel.ledger.default', 'null');
$app->forgetScopedInstances();

$run(BenchAudited::class, WARMUP, $offset);
$offset += WARMUP;
$results['audited, null ledger'] = $run(BenchAudited::class, ITERATIONS, $offset);

// The comparator with nothing around it: the row that says what the diff itself costs,
// separated from the write it happens inside.
$sample = [
    'active' => true,
    'email' => 'subject-1@example.com',
    'id' => 1,
    'name' => 'subject-1',
    'role' => 'admin',
    'score' => 1,
];

for ($i = 0; $i < WARMUP; $i++) {
    Diff::between([], $sample);
}

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    Diff::between([], $sample);
}

$results['diff only (no ledger, no write)'] = (hrtime(true) - $start) / 1_000_000;

// The resolver chain with nothing around it: ten resolvers over one data object, which is
// the fixed cost this version adds to every capture.
$engine = $app->make(ContextEngine::class);

for ($i = 0; $i < WARMUP; $i++) {
    $engine(new AuditData('model', 'created', Severity::Info, new DateTimeImmutable));
}

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $engine(new AuditData('model', 'created', Severity::Info, new DateTimeImmutable));
}

$results['context only (no ledger, no write)'] = (hrtime(true) - $start) / 1_000_000;

// The stage list with nothing around it: what this version adds to every capture, separated
// from the write it lands in and from the resolver chain it contains.
$pipeline = $app->make(Pipeline::class);

for ($i = 0; $i < WARMUP; $i++) {
    $pipeline->process(new AuditData('model', 'created', Severity::Info, new DateTimeImmutable));
}

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $pipeline->process(new AuditData('model', 'created', Severity::Info, new DateTimeImmutable));
}

$results['pipeline only (no ledger, no write)'] = (hrtime(true) - $start) / 1_000_000;

$baseline = $results['plain (not audited)'];

echo '| Variant | Writes | Total (ms) | Per write (µs) | Δ vs plain |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

foreach ($results as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        ITERATIONS,
        $total,
        $total * 1000 / ITERATIONS,
        $total === $baseline ? '—' : sprintf('%+.1f%%', ($total / $baseline - 1) * 100),
        PHP_EOL,
    );
}

printf(
    '%sPHP %s · %s (synchronous off, journal in memory) · %d columns per subject · %d iterations after %d warm-up writes%s',
    PHP_EOL,
    PHP_VERSION,
    DB::connection()->getDriverName(),
    count(Schema::getColumnListing('bench_subjects')),
    ITERATIONS,
    WARMUP,
    PHP_EOL,
);

@unlink($database);
