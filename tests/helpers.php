<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Restore\Planner;
use ElPandaPe\Sentinel\Security\Digester;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Security\Maskers;
use ElPandaPe\Sentinel\Security\Rekeyer;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\EventLog;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * @param  array<string, mixed>  $overrides
 */
function sentinelConfig(array $overrides = []): Config
{
    /** @var Repository $repository */
    $repository = app(Repository::class);

    foreach ($overrides as $key => $value) {
        $repository->set("sentinel.{$key}", $value);
    }

    return new Config($repository);
}

/**
 * @return list<string>
 */
function phpFilesOffending(string $pattern, ?string $directory = null): array
{
    $offenders = [];

    foreach (phpFiles($directory) as $file) {
        $contents = file_get_contents($file);

        if ($contents !== false && preg_match($pattern, $contents) === 1) {
            $offenders[] = $file;
        }
    }

    return $offenders;
}

/**
 * @return list<string>
 */
function phpFiles(?string $directory = null): array
{
    $files = [];

    $directories = $directory === null ? [dirname(__DIR__).'/src', __DIR__] : [$directory];

    foreach ($directories as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function auditsTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('audits');
}

function auditTagsTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('audit_tags');
}

function auditRelationsTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('audit_relations');
}

function transactionsTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('transactions');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transactionRow(array $overrides = []): array
{
    return [
        'id' => Str::ulid()->toString(),
        'name' => 'invoice-payment',
        'started_at' => '2026-08-28 10:00:00.000000',
        'audits_count' => 0,
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function linesOf(Audit $audit): array
{
    /** @var list<array<string, mixed>> $lines */
    $lines = $audit->getAttribute('changes') ?? [];

    return $lines;
}

/**
 * @return array<string, mixed>
 */
function lineOf(Audit $audit): array
{
    return linesOf($audit)[0];
}

function relationLine(
    string $relation = 'roles',
    string $id = '3',
    ?RelationOperation $operation = null,
): RelationLine {
    return new RelationLine($relation, $operation ?? RelationOperation::Attach, 'role', $id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relationRow(array $overrides = []): array
{
    return [
        'audit_id' => Str::ulid()->toString(),
        'relation' => 'roles',
        'operation' => 'attach',
        'related_type' => 'role',
        'related_id' => '3',
        'pivot_before' => null,
        'pivot_after' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function auditRow(array $overrides = []): array
{
    return [
        'id' => Str::ulid()->toString(),
        'stream' => 'global',
        'sequence' => 1,
        'audit_type' => 'model',
        'event' => 'created',
        'severity' => 'info',
        'source' => 'system',
        'context' => '[]',
        'payload_version' => 1,
        'algorithm' => 'sha256',
        'hash' => str_repeat('a', 64),
        'occurred_at' => '2026-08-26 10:00:00.000000',
        'created_at' => '2026-08-26 10:00:00.000000',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAudit(array $overrides = []): void
{
    DB::table(auditsTable())->insert(auditRow($overrides));
}

function createFixtureTables(): void
{
    Schema::create('fixture_int_subjects', static function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('fixture_uuid_subjects', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_ulid_subjects', static function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_actors', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_teams', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_members', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_posts', static function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('fixture_authors', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('code')->nullable();
    });

    Schema::create('fixture_articles', static function (Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->unsignedBigInteger('author_id')->nullable();
        $table->string('editor_code')->nullable();
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
    });

    Schema::create('fixture_labels', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('fixture_team_member', static function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('member_id');
        $table->string('role')->nullable();
        $table->string('expires_at')->nullable();
    });

    Schema::create('fixture_team_guest', static function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('member_id');
    });

    Schema::create('fixture_labelables', static function (Blueprint $table): void {
        $table->unsignedBigInteger('label_id');
        $table->unsignedBigInteger('labelable_id');
        $table->string('labelable_type');
        $table->string('note')->nullable();
    });

    Schema::create('fixture_audited_subjects', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('secret')->nullable();
        $table->string('status')->nullable();
        $table->string('price')->nullable();
        $table->dateTime('published_at', 6)->nullable();
        $table->json('options')->nullable();
        $table->boolean('active')->nullable();
        $table->softDeletes();
    });
}

/**
 * @param  array<array-key, mixed>  $value
 * @return array<array-key, mixed>
 */
function withSortedKeys(array $value): array
{
    ksort($value);

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = withSortedKeys($item);
        }
    }

    return $value;
}

function hasher(): Hasher
{
    return new Hasher(new JsonCanonicalizer);
}

function snapshotBuilder(): SnapshotBuilder
{
    return new SnapshotBuilder(sentinelConfig());
}

/**
 * @param  array<string, mixed>  $overrides
 */
function auditData(array $overrides = []): AuditData
{
    return new AuditData(...[
        'audit_type' => 'model',
        'event' => 'created',
        'severity' => Severity::Info,
        'occurred_at' => new DateTimeImmutable('2026-08-26 10:00:00.000000'),
        ...$overrides,
    ]);
}

/**
 * A trail shaped the way the indexes were built for: many subjects, many actors, many
 * tenants, and a severity where the one worth filtering by is the rare one. A planner reads
 * statistics, so an evenly spread column measures nothing about an index that exists for the
 * uneven case.
 */
function seedTheTrail(int $entries = 600): void
{
    $rows = [];

    for ($sequence = 1; $sequence <= $entries; $sequence++) {
        $bucket = $sequence % 60;

        $rows[] = auditRow([
            'sequence' => $sequence,
            // One kind of entry in sixty, so narrowing by it is a seek rather than the whole table.
            'audit_type' => $bucket === 7 ? 'transition' : 'model',
            'subject_type' => 'invoice',
            'subject_id' => (string) $bucket,
            'actor_type' => 'user',
            'actor_id' => (string) $bucket,
            'event' => 'event.'.$bucket,
            'severity' => $sequence % 150 === 0 ? 'critical' : 'info',
            'source' => $sequence % 2 === 0 ? 'http' : 'cli',
            'tenant_id' => 'tenant-'.$bucket,
            'transaction_id' => str_pad((string) $bucket, 26, '0', STR_PAD_LEFT),
            'trace_id' => str_pad((string) $bucket, 32, '0', STR_PAD_LEFT),
            'created_at' => new CarbonImmutable('2026-08-01 00:00:00')->addSeconds($sequence)->format('Y-m-d H:i:s.u'),
            // The reverse of the recording order, so an order by this clock cannot come out right by accident.
            'occurred_at' => new CarbonImmutable('2026-08-01 00:00:00')->addSeconds($entries - $sequence)->format('Y-m-d H:i:s.u'),
        ]);
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table(auditsTable())->insert($chunk);
    }

    $labels = [];

    foreach ($rows as $index => $row) {
        $labels[] = ['audit_id' => $row['id'], 'tag' => $index % 120 === 0 ? 'audited' : 'routine'];
    }

    foreach (array_chunk($labels, 100) as $chunk) {
        DB::table(auditTagsTable())->insert($chunk);
    }

    $lines = [];

    foreach ($rows as $index => $row) {
        $lines[] = [
            'audit_id' => $row['id'],
            'relation' => $index % 60 === 0 ? 'members' : 'guests',
            'operation' => $index % 2 === 0 ? 'attach' : 'detach',
            'related_type' => 'member',
            'related_id' => (string) ($index % 60),
            'pivot_before' => null,
            'pivot_after' => null,
        ];
    }

    foreach (array_chunk($lines, 100) as $chunk) {
        DB::table(auditRelationsTable())->insert($chunk);
    }

    foreach ([auditsTable(), auditTagsTable(), auditRelationsTable()] as $table) {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('analyze '.$table),
            'mysql' => DB::statement('analyze table '.$table),
            default => DB::statement('analyze'),
        };
    }
}

/**
 * The plan the engine chose for the statement the query compiled into, flattened to one
 * line. The statement is captured as it runs instead of rebuilt, so what gets explained is
 * what the driver issues and not an approximation of it.
 */
function planFor(AuditQuery $query): string
{
    $connection = DB::connection();
    $captured = ['sql' => '', 'bindings' => []];

    // The read of the trail itself, not the eager load of what hangs off it.
    DB::listen(static function (QueryExecuted $event) use (&$captured): void {
        if ($captured['sql'] === '' && str_contains($event->sql, auditsTable())) {
            $captured = ['sql' => $event->sql, 'bindings' => $event->bindings];
        }
    });

    $query->get();

    $sql = $captured['sql'];
    /** @var list<mixed> $bindings */
    $bindings = $captured['bindings'];

    return match ($connection->getDriverName()) {
        'mysql' => collect($connection->select('explain '.$sql, $bindings))
            ->map(static fn (object $row): string => (string) $row->EXPLAIN)
            ->implode(' | '),
        'pgsql' => collect($connection->select('explain '.$sql, $bindings))
            ->map(static fn (object $row): string => (string) $row->{'QUERY PLAN'})
            ->implode(' | '),
        default => collect($connection->select('explain query plan '.$sql, $bindings))
            ->map(static fn (object $row): string => (string) $row->detail)
            ->implode(' | '),
    };
}

/**
 * Whether the plan reaches the trail through an index. It asks about the audits table by name,
 * because a predicate can put a derived table in the plan whose own scan says nothing about how
 * the entries were found — the changed-field predicate does exactly that, over one constant row.
 */
function readsAnIndex(string $plan): bool
{
    $table = auditsTable();

    return match (DB::connection()->getDriverName()) {
        'mysql' => ! str_contains($plan, "Table scan on {$table}"),
        'pgsql' => ! str_contains($plan, "Seq Scan on {$table}"),
        default => str_contains($plan, 'USING INDEX') || str_contains($plan, 'USING COVERING INDEX'),
    };
}

function sortsOutsideTheIndex(string $plan): bool
{
    return match (DB::connection()->getDriverName()) {
        'mysql' => str_contains($plan, 'Sort:'),
        'pgsql' => str_contains($plan, 'Sort'),
        default => str_contains($plan, 'USE TEMP B-TREE FOR ORDER BY'),
    };
}

/**
 * The entries frozen in v0.3.0, put in the table exactly as they were sealed. created_at is
 * stamped here because it is not part of the canonical payload and the rows never carried
 * one: the order it gives them is what the query api is then read against.
 *
 * @return list<string>
 */
function seedTheFrozenTrail(): array
{
    $ids = [];
    $at = new CarbonImmutable('2026-08-26 12:00:00');

    foreach (GoldenLedger::entries() as [$attributes, , $hash]) {
        $audit = new Audit()->forceFill([...$attributes, 'hash' => $hash, 'created_at' => $at]);

        DB::table(auditsTable())->insert($audit->getAttributes());

        $ids[] = $audit->id;
        $at = $at->addSecond();
    }

    return $ids;
}

function ledger(): DatabaseLedger
{
    /** @var DatabaseLedger $ledger */
    $ledger = app(DatabaseLedger::class);

    return $ledger;
}

function auditQuery(?Ledger $ledger = null): AuditQuery
{
    return new AuditQuery($ledger ?? app(MemoryLedger::class));
}

/**
 * @param  list<Ledger>  $secondaries
 */
function fanout(Ledger $primary, array $secondaries, FanoutPolicy $policy = FanoutPolicy::Strict): FanoutLedger
{
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);

    return new FanoutLedger($primary, $secondaries, $policy, $events);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function stream(array $overrides = []): Stream
{
    return new Stream(sentinelConfig($overrides), app());
}

/**
 * The entries of a subject, read straight from the ledger: after a hard delete the
 * model is gone and its relation cannot be walked any more.
 *
 * @return AuditCollection<int, Audit>
 */
function auditsOf(Model $subject): AuditCollection
{
    /** @var AuditCollection<int, Audit> $audits */
    $audits = Audit::query()
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', (string) $subject->getKey())
        ->orderBy('id')
        ->get();

    return $audits;
}

/**
 * An entry about a record, sealed by the real ledger so its hash is the one the restorer
 * checks. Writing the row by hand would leave a hash that no longer matches its own payload,
 * which is the condition one of these tests is about and the last thing the others want.
 *
 * @param  array<string, mixed>  $before
 * @param  array<string, mixed>  $overrides
 */
function restorableEntry(Model $subject, array $before, array $overrides = []): Audit
{
    return ledger()->write(auditData([
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(),
        'event' => 'updated',
        'before' => $before,
        ...$overrides,
    ]));
}

/**
 * The entry as the table holds it now. A test that changes a row behind the model's back is
 * asking what the package does with what is stored, not with what it built.
 */
function reread(Audit $audit): Audit
{
    /** @var Audit $fresh */
    $fresh = Audit::query()->findOrFail($audit->id);

    return $fresh;
}

/**
 * A model that throws on its way to the row. It is where a restoration can fail after it has
 * decided everything and before anything is written down, which is the moment the transaction
 * around it exists for.
 *
 * @param  class-string<Model>  $model
 */
function refuseToSave(string $model): void
{
    app(Dispatcher::class)->listen('eloquent.saving: '.$model, static function (): void {
        throw new RuntimeException('the record refused the write');
    });
}

function planner(): Planner
{
    /** @var Planner $planner */
    $planner = app(Planner::class);

    return $planner;
}

function verifier(?Ledger $ledger = null): Verifier
{
    /** @var Verifier $verifier */
    $verifier = app(Verifier::class, array_filter(['ledger' => $ledger]));

    return $verifier;
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return list<string>
 */
function translationKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $keys = is_array($value)
            ? [...$keys, ...translationKeys($value, $prefix.$key.'.')]
            : [...$keys, $prefix.$key];
    }

    sort($keys);

    return $keys;
}

/**
 * A second connection to the same database, so a test can race the ledger for real.
 * An in-memory SQLite database cannot have one: a second connection is a second database.
 */
function rivalConnection(): ?string
{
    /** @var Repository $repository */
    $repository = app(Repository::class);

    $default = $repository->get('database.default');
    $config = is_string($default) ? $repository->get("database.connections.{$default}") : null;

    if (! is_array($config) || ($config['database'] ?? null) === ':memory:') {
        return null;
    }

    $repository->set('database.connections.sentinel_rival', $config);

    return 'sentinel_rival';
}

function lockTimeout(): string
{
    return DB::connection()->getDriverName() === 'pgsql'
        ? "set lock_timeout to '500ms'"
        : 'set innodb_lock_wait_timeout = 1';
}

/**
 * Slips a rival writer in between the tail read and the insert. Whether it gets through
 * is the engine's business: MySQL holds it on the gap lock, PostgreSQL lets it commit.
 */
function raceTheGate(string $rival): void
{
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $rival): void {
        if ($raced || ! str_contains($query->sql, 'desc')) {
            return;
        }

        $raced = true;

        rescue(function () use ($rival): void {
            DB::connection($rival)->statement(lockTimeout());
            DB::connection($rival)->table(auditsTable())->insert(auditRow(['sequence' => 1]));
        }, report: false);
    });
}

/**
 * @return list<array{path: string, op: string, old?: mixed, new: mixed}>
 */
function diffEntries(mixed $before, mixed $after): array
{
    return Diff::between($before, $after)->toArray();
}

function runtime(): Runtime
{
    /** @var Runtime $runtime */
    $runtime = app(Runtime::class);

    return $runtime;
}

/**
 * Puts a request where a real one lands: bound in the container and latched on the
 * runtime, which is what the router's own event does when a request reaches it.
 *
 * @param  array<string, string>  $headers
 */
function httpRequest(string $uri, array $headers = []): Request
{
    $request = Request::create($uri);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    app()->instance('request', $request);
    runtime()->enteredRequest($request);

    return $request;
}

/**
 * @param  list<class-string<\ElPandaPe\Sentinel\Contracts\Transformer>>  $stages
 */
function stagedPipeline(array $stages): void
{
    /** @var Repository $repository */
    $repository = app(Repository::class);

    $repository->set('sentinel.pipeline', $stages);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function protectedEntry(array $overrides = []): array
{
    return [
        'subject_type' => ProtectedSubject::class,
        'subject_id' => '7',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function digester(array $overrides = []): Digester
{
    return new Digester(sentinelConfig($overrides), new JsonCanonicalizer);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function maskers(array $overrides = []): Maskers
{
    return new Maskers(app(), sentinelConfig($overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function encryptedEntry(array $overrides = []): array
{
    return ['subject_type' => EncryptedSubject::class, 'subject_id' => '7', ...$overrides];
}

function keyring(): Keyring
{
    /** @var Keyring $keyring */
    $keyring = app(Keyring::class);

    return $keyring;
}

function rekeyer(): Rekeyer
{
    /** @var Rekeyer $rekeyer */
    $rekeyer = app(Rekeyer::class);

    return $rekeyer;
}

/**
 * The events the package dispatches, kept whole. A fake would stop the pipeline from
 * dispatching at all, and what this has to prove is what the real payloads carried.
 */
function recordEveryEvent(): void
{
    $log = new EventLog;

    app()->instance(EventLog::class, $log);

    app(Dispatcher::class)->listen('*', static function (string $name, array $payload) use ($log): void {
        $log->record($name, $payload);
    });
}

function eventPayloads(): string
{
    return eventLog()->contents();
}

function recordedEvents(): int
{
    return eventLog()->count();
}

function eventLog(): EventLog
{
    /** @var EventLog $log */
    $log = app(EventLog::class);

    return $log;
}

function pipeline(): Pipeline
{
    /** @var Pipeline $pipeline */
    $pipeline = app(Pipeline::class);

    return $pipeline;
}

function discard(): Discard
{
    /** @var Discard $discard */
    $discard = app(Discard::class);

    return $discard;
}

function contextEngine(): ContextEngine
{
    /** @var ContextEngine $engine */
    $engine = app(ContextEngine::class);

    return $engine;
}

/**
 * @param  list<string>  $package
 * @param  list<string>  $published
 * @return array{string, string, string}
 */
function migrationDirectories(array $package = [], array $published = []): array
{
    $root = sys_get_temp_dir().'/sentinel-'.uniqid();

    foreach (['package' => $package, 'published' => $published] as $directory => $files) {
        mkdir("{$root}/{$directory}", recursive: true);

        foreach ($files as $file) {
            touch("{$root}/{$directory}/{$file}");
        }
    }

    return [$root, "{$root}/package", "{$root}/published"];
}

function discardMigrationDirectories(string $root): void
{
    foreach (['package', 'published'] as $directory) {
        $files = glob("{$root}/{$directory}/*.php");

        foreach (is_array($files) ? $files : [] as $file) {
            unlink($file);
        }

        rmdir("{$root}/{$directory}");
    }

    rmdir($root);
}

/**
 * @return array{event: string, changes: list<array{path: string, op: string, old: string, new: string}>}
 */
function changing(string $event, string ...$paths): array
{
    return [
        'event' => $event,
        'changes' => array_map(
            static fn (string $path): array => ['path' => $path, 'op' => 'replace', 'old' => 'a', 'new' => 'b'],
            $paths,
        ),
    ];
}

/**
 * @return array{subject_type: string, subject_id: string, after: array{total: int, status: string}}
 */
function versioned(int $total, string $subject = '7'): array
{
    return [
        'subject_type' => AuditedSubject::class,
        'subject_id' => $subject,
        'after' => ['total' => $total, 'status' => 'open'],
    ];
}

/**
 * Every leaf key with the set of :placeholders its line carries. Two languages agreeing on keys
 * but not on holes renders a literal colon-word to whoever reads the second one, and nothing
 * else in the suite would notice.
 *
 * @param  array<array-key, mixed>  $lines
 * @return array<string, list<string>>
 */
function placeholders(array $lines, string $prefix = ''): array
{
    $found = [];

    foreach ($lines as $key => $line) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($line)) {
            $found = [...$found, ...placeholders($line, $path)];

            continue;
        }

        preg_match_all('/:([a-z_]+)/', is_string($line) ? $line : '', $matches);
        $names = $matches[1];
        sort($names);

        $found[$path] = array_values($names);
    }

    return $found;
}

function presenter(): AuditPresenter
{
    /** @var AuditPresenter $presenter */
    $presenter = app(AuditPresenter::class);

    return $presenter;
}

/**
 * Whether the plan reaches a table through one of its indexes instead of walking it. Which index
 * answers is the planner's business, and for the labels table it is not even the same one across
 * versions of the same engine: SQLite turns the correlated exists into a semi-join from 3.51 on
 * and seeks the reversed index, and before that it evaluates the exists per row with audit_id
 * already fixed, where the unique pair is the right one to seek. Both are a seek, so naming one
 * of them would be a gate that moves with the patch version underneath it.
 *
 * The table has to be named in the plan at all. On the two engines read by the absence of a scan,
 * a plan that never mentions it would otherwise pass for having reached it.
 */
function reachesByIndex(string $plan, string $table): bool
{
    if (! str_contains($plan, $table)) {
        return false;
    }

    return match (DB::connection()->getDriverName()) {
        'mysql' => ! str_contains($plan, "Table scan on {$table}"),
        'pgsql' => ! str_contains($plan, "Seq Scan on {$table}"),
        default => ! str_contains($plan, "SCAN {$table}"),
    };
}

/**
 * A recognisable identifier of exactly the width the column holds. Counting the padding by hand is
 * how a twenty-five character id gets written, and only PostgreSQL says so: char(26) pads with a
 * space there and MySQL and SQLite trim it away, so the mistake surfaces in one engine out of three.
 */
function frozenUlid(string $suffix): string
{
    return str_pad('01J', 26 - strlen($suffix), '0').$suffix;
}
