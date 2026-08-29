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
use ElPandaPe\Sentinel\Benchmarks\BenchTransitioning;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Dispatch\Settlement;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

// A fact stated outright, against the model change it is measured beside. It carries no snapshot
// and no diff, so what is left is the pipeline, the context and the write — which is the number
// that says whether "the same ledger, no shortcuts" costs anything extra.
const EVENT_ITERATIONS = 2000;

$sentinel = $app->make(Sentinel::class);

for ($i = 0; $i < WARMUP; $i++) {
    $sentinel->event('bench.stated')->record();
}

$start = hrtime(true);

for ($i = 0; $i < EVENT_ITERATIONS; $i++) {
    $sentinel->event('bench.stated')->record();
}

$results['domain event, no subject'] = (hrtime(true) - $start) / 1_000_000;

$subject = BenchAudited::query()->firstOrFail();

for ($i = 0; $i < WARMUP; $i++) {
    $sentinel->event('bench.stated')->subject($subject)->record();
}

$start = hrtime(true);

for ($i = 0; $i < EVENT_ITERATIONS; $i++) {
    $sentinel->event('bench.stated')->subject($subject)->record();
}

$results['domain event, with a subject'] = (hrtime(true) - $start) / 1_000_000;

// What a lifeline costs. Two questions, not one: what declaring a state column adds to every
// audited update that does not touch it — the check itself — and what an update that does touch it
// costs once it is written as a transition instead. The last row is the same fact stated outright,
// which carries no snapshot pair to compare.
//
// Each variant gets a record of its own. The ledger reads max('version') per subject, so a subject
// that another variant already wrote two thousand entries about is a subject whose next write is
// slower for a reason that has nothing to do with transitions.
const TRANSITION_ITERATIONS = 2000;

/**
 * The state moves or another column does; either way the save is the same call, so what the two
 * rows differ by is what the package did with it and nothing else.
 */
$moving = static function (Model $record, int $times, bool $state): float {
    for ($i = 0; $i < WARMUP; $i++) {
        $record->forceFill($state ? ['role' => $i % 2 === 0 ? 'admin' : 'editor'] : ['score' => $i % 100])->save();
    }

    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        $record->forceFill($state ? ['role' => $i % 2 === 0 ? 'admin' : 'editor'] : ['score' => $i % 100])->save();
    }

    return (hrtime(true) - $start) / 1_000_000;
};

/**
 * @param  class-string<Model>  $model
 */
$mover = static function (string $model, int $offset): Model {
    /** @var Model $record */
    $record = $model::query()->create([  // @phpstan-ignore-line
        'name' => 'mover-'.$offset,
        'email' => 'mover-'.$offset.'@example.com',
        'role' => 'editor',
        'score' => 0,
        'active' => true,
    ]);

    return $record;
};

$transitionResults = [];

foreach ([
    'update of another column, no state column declared' => [BenchAudited::class, false],
    'update of another column, state column declared' => [BenchTransitioning::class, false],
    'update of the state column, not declared, written as an update' => [BenchAudited::class, true],
    'update of the state column, declared, written as a transition' => [BenchTransitioning::class, true],
] as $label => [$model, $state]) {
    $offset += WARMUP + TRANSITION_ITERATIONS;
    $transitionResults[$label] = $moving($mover($model, $offset), TRANSITION_ITERATIONS, $state);
}

$offset += WARMUP + TRANSITION_ITERATIONS;
$stated = $mover(BenchTransitioning::class, $offset);

for ($i = 0; $i < WARMUP; $i++) {
    $sentinel->transition($stated, from: 'editor', to: 'admin')->record();
}

$start = hrtime(true);

for ($i = 0; $i < TRANSITION_ITERATIONS; $i++) {
    $sentinel->transition($stated, from: 'editor', to: 'admin')->record();
}

$transitionResults['transition stated outright'] = (hrtime(true) - $start) / 1_000_000;

// What putting a record back costs, against the audited update it replaces rather than against the
// v0.4.0 baseline: that one measures a created, which builds one snapshot where an update builds
// two and compares them. A restoration does everything an audited update does and, before it, reads
// the entry's own hash back, photographs the record, and asks the schema which columns still exist.
//
// Each row gets its own record, for the reason the transition rows do: they all write the same
// number of entries about their subject, so the ledger's max('version') costs them the same.
const RESTORE_ITERATIONS = 1000;

$restoring = static function (Audit $first, Audit $second, int $times, bool $whole): float {
    $fields = $whole ? null : ['score'];

    for ($i = 0; $i < WARMUP; $i++) {
        ($i % 2 === 0 ? $first : $second)->restore($fields);
    }

    $start = hrtime(true);

    for ($i = 0; $i < $times; $i++) {
        ($i % 2 === 0 ? $first : $second)->restore($fields);
    }

    return (hrtime(true) - $start) / 1_000_000;
};

/**
 * Two entries that portray the same record with a different score, so alternating between them
 * always has something to move. A restoration that finds the value already there writes nothing,
 * and a row of those would be measuring the refusal instead of the restoration.
 *
 * @return array{Audit, Audit}
 */
$anchored = static function (int $offset) use ($mover): array {
    $record = $mover(BenchAudited::class, $offset);
    $record->forceFill(['score' => 1])->save();

    $key = $record->getKey();

    /** @var array{Audit, Audit} $anchors */
    $anchors = Audit::query()
        ->where('subject_type', $record->getMorphClass())
        ->where('subject_id', is_string($key) || is_int($key) ? (string) $key : '')
        ->orderBy('id')
        ->take(2)
        ->get()
        ->all();

    return $anchors;
};

$offset += WARMUP + TRANSITION_ITERATIONS;
$restoreReference = $moving($mover(BenchAudited::class, $offset), RESTORE_ITERATIONS, false);

$offset += WARMUP + RESTORE_ITERATIONS;
[$first, $second] = $anchored($offset);
$restoreWhole = $restoring($first, $second, RESTORE_ITERATIONS, true);

$offset += WARMUP + RESTORE_ITERATIONS;
[$first, $second] = $anchored($offset);
$restoreOneField = $restoring($first, $second, RESTORE_ITERATIONS, false);

/*
 * The lifecycle events are dispatched inline, on the write path, so the question this answers is
 * what listening costs the request that saved the model. The reference is the same audited update
 * the restore section uses, on an application that registers nothing.
 *
 * The listeners do nothing on purpose. What is being measured is the cost of the hook, not the
 * cost of whatever an application decides to hang off it — which is unbounded, and which is why
 * the README says to queue it.
 */
const LISTENER_ITERATIONS = 1000;

$offset += WARMUP + RESTORE_ITERATIONS;
$listenerReference = $moving($mover(BenchAudited::class, $offset), LISTENER_ITERATIONS, false);

$events = $app->make(Dispatcher::class);
$events->listen(Auditing::class, static fn (): null => null);

$offset += WARMUP + LISTENER_ITERATIONS;
$listenerOnAuditing = $moving($mover(BenchAudited::class, $offset), LISTENER_ITERATIONS, false);

$events->listen(AuditCreating::class, static fn (): null => null);
$events->listen(AuditCreated::class, static fn (): null => null);

$offset += WARMUP + LISTENER_ITERATIONS;
$listenerOnAll = $moving($mover(BenchAudited::class, $offset), LISTENER_ITERATIONS, false);

$events->forget(Auditing::class);
$events->forget(AuditCreating::class);
$events->forget(AuditCreated::class);

/*
 * The three questions a performance mode has to answer, and they are not the same number. What the
 * request pays is what the user waits for; what the worker pays is what the database sees; and the
 * two together are what the mode costs, which is never less than what sync costs — deferring moves
 * work, it does not remove it.
 *
 * Queued writes are measured against the database queue driver rather than a null one, because a
 * queue that discards the job is not measuring the enqueue. Redis would be faster; this is the
 * slowest realistic floor.
 */
const MODE_ITERATIONS = 1000;

Schema::create('jobs', static function (Blueprint $table): void {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});

$app->make('config')->set('queue.default', 'bench');
$app->make('config')->set('queue.connections.bench', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);

/*
 * The table grows through the pass, so whichever mode runs last is the one carrying the larger
 * index. Sync runs first on purpose: it makes the queued figure the conservative one rather than
 * the flattering one.
 */
$offset += WARMUP + LISTENER_ITERATIONS;
$run(BenchPlain::class, WARMUP, $offset);
$offset += WARMUP;
$plainRequest = $run(BenchPlain::class, MODE_ITERATIONS, $offset);
$offset += MODE_ITERATIONS;

$app->make('config')->set('sentinel.mode', 'sync');
$app->forgetScopedInstances();

$run(BenchAudited::class, WARMUP, $offset);
$offset += WARMUP;
$syncRequest = $run(BenchAudited::class, MODE_ITERATIONS, $offset);
$offset += MODE_ITERATIONS;

$run(BenchSnapshotless::class, WARMUP, $offset);
$offset += WARMUP;
$syncRequestBare = $run(BenchSnapshotless::class, MODE_ITERATIONS, $offset);
$offset += MODE_ITERATIONS;

$app->make('config')->set('sentinel.mode', 'queue');
$app->forgetScopedInstances();

$run(BenchAudited::class, WARMUP, $offset);
$offset += WARMUP;
$queuedRequest = $run(BenchAudited::class, MODE_ITERATIONS, $offset);
$offset += MODE_ITERATIONS;

$run(BenchSnapshotless::class, WARMUP, $offset);
$offset += WARMUP;
$queuedRequestBare = $run(BenchSnapshotless::class, MODE_ITERATIONS, $offset);
$offset += MODE_ITERATIONS;

/**
 * What the worker pays, with the queue itself left out: the payload it was handed, read back and
 * settled. The worker loop, the reserve and the delete belong to the framework and are the same
 * whatever is inside the job.
 */
$settling = static function (int $times) use ($app): float {
    $settlement = $app->make(Settlement::class);
    $runtime = $app->make(Runtime::class);

    $payloads = [];

    for ($i = 0; $i < $times; $i++) {
        $payloads[] = new AuditData(
            audit_type: 'model',
            event: 'created',
            severity: Severity::Info,
            occurred_at: new DateTimeImmutable,
            capture_id: (string) Str::ulid(),
            after: ['name' => 'subject', 'email' => 'subject@example.com', 'role' => 'admin', 'score' => 1, 'active' => true],
        )->toPayload();
    }

    $start = hrtime(true);

    foreach ($payloads as $payload) {
        $runtime->whileWritingAudit(static fn (): mixed => $settlement->settleOnce(AuditData::fromPayload($payload)));
    }

    return (hrtime(true) - $start) / 1_000_000;
};

$app->make('config')->set('sentinel.mode', 'sync');
$app->forgetScopedInstances();

$settling(WARMUP);
$workerSettling = $settling(MODE_ITERATIONS);

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

echo PHP_EOL, '| Transition variant | Writes | Total (ms) | Per write (µs) | Δ vs an audited update |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

$transitionBaseline = array_values($transitionResults)[0];

foreach ($transitionResults as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        TRANSITION_ITERATIONS,
        $total,
        $total * 1000 / TRANSITION_ITERATIONS,
        $total === $transitionBaseline ? '—' : sprintf('%+.1f%%', ($total / $transitionBaseline - 1) * 100),
        PHP_EOL,
    );
}

echo PHP_EOL, '| Restore variant | Writes | Total (ms) | Per write (µs) | Δ vs an audited update |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

foreach ([
    'audited update of one column' => $restoreReference,
    'restore, whole recorded state' => $restoreWhole,
    'restore, one named field' => $restoreOneField,
] as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        RESTORE_ITERATIONS,
        $total,
        $total * 1000 / RESTORE_ITERATIONS,
        $total === $restoreReference ? '—' : sprintf('%+.1f%%', ($total / $restoreReference - 1) * 100),
        PHP_EOL,
    );
}

echo PHP_EOL, '| Listener variant | Writes | Total (ms) | Per write (µs) | Δ vs no listener |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

foreach ([
    'audited update, nothing listening' => $listenerReference,
    'audited update, one listener on Auditing' => $listenerOnAuditing,
    'audited update, a listener on all three' => $listenerOnAll,
] as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        LISTENER_ITERATIONS,
        $total,
        $total * 1000 / LISTENER_ITERATIONS,
        $total === $listenerReference ? '—' : sprintf('%+.1f%%', ($total / $listenerReference - 1) * 100),
        PHP_EOL,
    );
}

echo PHP_EOL, '| Performance mode | Writes | Total (ms) | Per write (µs) | Δ vs plain |', PHP_EOL;
echo '|---|---|---|---|---|', PHP_EOL;

foreach ([
    'plain (not audited)' => $plainRequest,
    'sync, in the request, snapshots on' => $syncRequest,
    'sync, in the request, snapshots off' => $syncRequestBare,
    'queue, what the request pays, snapshots on' => $queuedRequest,
    'queue, what the request pays, snapshots off' => $queuedRequestBare,
    'queue, what the worker pays to settle one' => $workerSettling,
] as $label => $total) {
    printf(
        '| %s | %d | %.1f | %.1f | %s |%s',
        $label,
        MODE_ITERATIONS,
        $total,
        $total * 1000 / MODE_ITERATIONS,
        $total === $plainRequest ? '—' : sprintf('%+.1f%%', ($total / $plainRequest - 1) * 100),
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
