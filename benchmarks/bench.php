<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Benchmarks\BenchArticle;
use ElPandaPe\Sentinel\Benchmarks\BenchAudited;
use ElPandaPe\Sentinel\Benchmarks\BenchAuthor;
use ElPandaPe\Sentinel\Benchmarks\BenchLabelled;
use ElPandaPe\Sentinel\Benchmarks\BenchLooseArticle;
use ElPandaPe\Sentinel\Benchmarks\BenchMember;
use ElPandaPe\Sentinel\Benchmarks\BenchPlain;
use ElPandaPe\Sentinel\Benchmarks\BenchPlainArticle;
use ElPandaPe\Sentinel\Benchmarks\BenchPlainTeam;
use ElPandaPe\Sentinel\Benchmarks\BenchProtected;
use ElPandaPe\Sentinel\Benchmarks\BenchSnapshotless;
use ElPandaPe\Sentinel\Benchmarks\BenchTeam;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Sentinel;
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

Schema::create('bench_teams', static function (Blueprint $table): void {
    $table->id();
});

Schema::create('bench_members', static function (Blueprint $table): void {
    $table->id();
});

Schema::create('bench_team_member', static function (Blueprint $table): void {
    $table->unsignedBigInteger('team_id');
    $table->unsignedBigInteger('member_id');
    $table->string('role')->nullable();
});

Schema::create('bench_authors', static function (Blueprint $table): void {
    $table->id();
});

Schema::create('bench_articles', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('author_id')->nullable();
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
    'audited, two labels' => BenchLabelled::class,
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

// A pivot operation, which no eloquent event announces and which this version audits by
// photographing the pivot rows either side of it. The pair is measured rather than the attach
// alone: a detach has to read the rows before they are gone, so the two are not the same cost.
const PIVOT_ITERATIONS = 500;
const SYNC_SIZE = 50;

BenchPlainTeam::query()->create(['id' => 1]);
BenchPlainTeam::query()->create(['id' => 2]);

for ($i = 1; $i <= SYNC_SIZE; $i++) {
    BenchMember::query()->create(['id' => $i]);
}

/**
 * @param  class-string<BenchPlainTeam|BenchTeam>  $model
 */
$churn = static function (string $model, int $team, int $times): float {
    /** @var BenchPlainTeam|BenchTeam $owner */
    $owner = $model::query()->findOrFail($team);  // @phpstan-ignore-line staticMethod.dynamicName
    $relation = $owner->members();
    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $relation->attach(1, ['role' => 'lead']);
        $relation->detach(1);
    }

    return (hrtime(true) - $start) / 1_000_000;
};

/**
 * @param  class-string<BenchPlainTeam|BenchTeam>  $model
 */
$churnSync = static function (string $model, int $team, int $times, int $size): float {
    /** @var BenchPlainTeam|BenchTeam $owner */
    $owner = $model::query()->findOrFail($team);  // @phpstan-ignore-line staticMethod.dynamicName
    $relation = $owner->members();
    $everyone = range(1, $size);
    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $relation->sync($everyone);
        $relation->sync([]);
    }

    return (hrtime(true) - $start) / 1_000_000;
};

$churn(BenchPlainTeam::class, 1, WARMUP);
$pivotBaseline = $churn(BenchPlainTeam::class, 1, PIVOT_ITERATIONS);

$churn(BenchTeam::class, 2, WARMUP);
$pivotAudited = $churn(BenchTeam::class, 2, PIVOT_ITERATIONS);

$churnSync(BenchPlainTeam::class, 1, 10, SYNC_SIZE);
$syncBaseline = $churnSync(BenchPlainTeam::class, 1, 100, SYNC_SIZE);

$churnSync(BenchTeam::class, 2, 10, SYNC_SIZE);
$syncAudited = $churnSync(BenchTeam::class, 2, 100, SYNC_SIZE);

// A child changing hands, which no eloquent event announces either: the update fires on the child
// and the two parents that lived the change hear nothing. Three variants because the interesting
// number is not what auditing costs but what the two parent entries cost on top of the child's own.
const HANDOVER_ITERATIONS = 500;

BenchAuthor::query()->create(['id' => 1]);
BenchAuthor::query()->create(['id' => 2]);

foreach ([1, 2, 3] as $article) {
    BenchPlainArticle::query()->create(['id' => $article, 'author_id' => 1]);
}

/**
 * @param  class-string<BenchArticle|BenchLooseArticle|BenchPlainArticle>  $model
 */
$handover = static function (string $model, int $article, int $times): float {
    /** @var BenchArticle|BenchLooseArticle|BenchPlainArticle $child */
    $child = $model::query()->findOrFail($article);  // @phpstan-ignore-line staticMethod.dynamicName
    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $child->update(['author_id' => $i % 2 === 0 ? 2 : 1]);
    }

    return (hrtime(true) - $start) / 1_000_000;
};

$handover(BenchPlainArticle::class, 1, WARMUP);
$handoverBaseline = $handover(BenchPlainArticle::class, 1, HANDOVER_ITERATIONS);

$handover(BenchLooseArticle::class, 2, WARMUP);
$handoverChild = $handover(BenchLooseArticle::class, 2, HANDOVER_ITERATIONS);

$handover(BenchArticle::class, 3, WARMUP);
$handoverParents = $handover(BenchArticle::class, 3, HANDOVER_ITERATIONS);

// What waiting for the commit costs, and what naming the operation costs on top. Deferral is not
// a performance feature — it is what stops the ledger claiming a fact a rollback undid — so the
// number that matters is that it is not expensive, not that it is fast.
const DEFERRED_ITERATIONS = 500;

/**
 * @param  class-string<Model>  $model
 */
$transacted = static function (string $model, int $times, int $offset): float {
    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $n = $offset + $i;

        DB::transaction(static function () use ($model, $n): void {
            $model::query()->create([  // @phpstan-ignore-line staticMethod.dynamicName
                'name' => 'subject-'.$n,
                'email' => 'subject-'.$n.'@example.com',
                'role' => $n % 2 === 0 ? 'admin' : 'editor',
                'score' => $n % 100,
                'active' => $n % 3 !== 0,
            ]);
        });
    }

    return (hrtime(true) - $start) / 1_000_000;
};

/**
 * A header costs an insert, an update and one pass of the context resolvers, and that is per
 * operation rather than per entry. Measured at one entry per operation it is the worst case
 * there is; the second size is what says how fast it amortises.
 *
 * @param  class-string<Model>  $model
 */
$correlated = static function (string $model, int $writes, int $operations, int $offset) use ($app): float {
    $sentinel = $app->make(Sentinel::class);
    $start = hrtime(true);

    for ($i = 0; $i < $operations; $i++) {
        $n = $offset + $i * $writes;

        $sentinel->transaction('bench-operation', static function () use ($model, $n, $writes): void {
            for ($w = 0; $w < $writes; $w++) {
                $model::query()->create([  // @phpstan-ignore-line staticMethod.dynamicName
                    'name' => 'subject-'.($n + $w),
                    'email' => 'subject-'.($n + $w).'@example.com',
                    'role' => ($n + $w) % 2 === 0 ? 'admin' : 'editor',
                    'score' => ($n + $w) % 100,
                    'active' => ($n + $w) % 3 !== 0,
                ]);
            }
        });
    }

    return (hrtime(true) - $start) / 1_000_000;
};

$app->make('config')->set('sentinel.ledger.default', 'database');
$app->make('config')->set('sentinel.transactions.after_commit', true);
$app->forgetScopedInstances();

$transacted(BenchPlain::class, WARMUP, $offset);
$offset += WARMUP;
$deferredPlain = $transacted(BenchPlain::class, DEFERRED_ITERATIONS, $offset);
$offset += DEFERRED_ITERATIONS;

$transacted(BenchAudited::class, WARMUP, $offset);
$offset += WARMUP;
$deferredOn = $transacted(BenchAudited::class, DEFERRED_ITERATIONS, $offset);
$offset += DEFERRED_ITERATIONS;

$app->make('config')->set('sentinel.transactions.after_commit', false);
$app->forgetScopedInstances();

$transacted(BenchAudited::class, WARMUP, $offset);
$offset += WARMUP;
$deferredOff = $transacted(BenchAudited::class, DEFERRED_ITERATIONS, $offset);
$offset += DEFERRED_ITERATIONS;

$app->make('config')->set('sentinel.transactions.after_commit', true);
$app->forgetScopedInstances();

$correlated(BenchAudited::class, 1, WARMUP, $offset);
$offset += WARMUP;
$correlatedAlone = $correlated(BenchAudited::class, 1, DEFERRED_ITERATIONS, $offset);
$offset += DEFERRED_ITERATIONS;

$correlated(BenchAudited::class, 5, WARMUP / 5, $offset);
$offset += WARMUP;
$correlatedFive = $correlated(BenchAudited::class, 5, DEFERRED_ITERATIONS / 5, $offset);

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

echo PHP_EOL, '| Pivot variant | Operations | Total (ms) | Per operation (µs) | Δ vs plain |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

$pivot = [
    'attach + detach, not audited' => [$pivotBaseline, PIVOT_ITERATIONS * 2, $pivotBaseline],
    'attach + detach, audited' => [$pivotAudited, PIVOT_ITERATIONS * 2, $pivotBaseline],
    'sync of '.SYNC_SIZE.' then empty, not audited' => [$syncBaseline, 200, $syncBaseline],
    'sync of '.SYNC_SIZE.' then empty, audited' => [$syncAudited, 200, $syncBaseline],
];

foreach ($pivot as $label => [$total, $operations, $against]) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        $operations,
        $total,
        $total * 1000 / $operations,
        $total === $against ? '—' : sprintf('%+.1f%%', ($total / $against - 1) * 100),
        PHP_EOL,
    );
}

echo PHP_EOL, '| Hand-over variant | Operations | Total (ms) | Per operation (µs) | Δ vs plain |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

$handovers = [
    'foreign key change, not audited' => $handoverBaseline,
    'foreign key change, child audited' => $handoverChild,
    'foreign key change, child and both parents audited' => $handoverParents,
];

foreach ($handovers as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        HANDOVER_ITERATIONS,
        $total,
        $total * 1000 / HANDOVER_ITERATIONS,
        $total === $handoverBaseline ? '—' : sprintf('%+.1f%%', ($total / $handoverBaseline - 1) * 100),
        PHP_EOL,
    );
}

echo PHP_EOL, '| Deferral variant | Writes | Total (ms) | Per write (µs) | Δ vs plain |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

$deferrals = [
    'inside a transaction, not audited' => $deferredPlain,
    'inside a transaction, audited, after_commit on' => $deferredOn,
    'inside a transaction, audited, after_commit off' => $deferredOff,
    'inside a named business operation, one entry each' => $correlatedAlone,
    'inside a named business operation, five entries each' => $correlatedFive,
];

foreach ($deferrals as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        DEFERRED_ITERATIONS,
        $total,
        $total * 1000 / DEFERRED_ITERATIONS,
        $total === $deferredPlain ? '—' : sprintf('%+.1f%%', ($total / $deferredPlain - 1) * 100),
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
