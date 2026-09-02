<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Archive\BatchReader;
use ElPandaPe\Sentinel\Archive\BatchWriter;
use ElPandaPe\Sentinel\Archive\Manifest;
use ElPandaPe\Sentinel\Archive\Rehydrator;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Integrity\Checkpoints;
use ElPandaPe\Sentinel\Integrity\Content;
use ElPandaPe\Sentinel\Integrity\Fold;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\HmacSigner;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Integrity\NullSigner;
use ElPandaPe\Sentinel\Integrity\OpenSslSigner;
use ElPandaPe\Sentinel\Integrity\Projections;
use ElPandaPe\Sentinel\Integrity\Signers;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Ledger\ArchiveLedger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Mass\Criteria;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Restore\Planner;
use ElPandaPe\Sentinel\Retention\Archiver;
use ElPandaPe\Sentinel\Retention\Cascade;
use ElPandaPe\Sentinel\Retention\Frontiers;
use ElPandaPe\Sentinel\Retention\Pruner;
use ElPandaPe\Sentinel\Retention\RetainedPredicate;
use ElPandaPe\Sentinel\Retention\Schedule;
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
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
 * The criteria of a query built here rather than one the package built, so what is under test is
 * the serialiser and not the caller that reached it.
 *
 * @param  Closure(QueryBuilder): void  $build
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function massCriteria(Closure $build, array $overrides = []): array
{
    $query = DB::table('fixture_audited_subjects');

    $build($query);

    return new Criteria(sentinelConfig($overrides))->of($query);
}

/**
 * @param  array<string, mixed>  $criteria
 * @return list<array<string, mixed>>
 */
function massWheres(array $criteria): array
{
    /** @var list<array<string, mixed>> $wheres */
    $wheres = $criteria['wheres'] ?? [];

    return $wheres;
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

/**
 * A real chain of the given length, written through the database ledger so every link is the one
 * the ledger built. Nothing here forges a hash, which is what lets a purge test verify afterwards.
 */
function seedChain(int $entries, string $stream = 'global'): void
{
    foreach (range(1, $entries) as $ignored) {
        ledger()->write(auditData(['stream' => $stream]));
    }
}

/**
 * Moves when an entry was recorded, which is the clock retention reads. It is deliberately not part
 * of the canonical payload, so a test can age a range without touching what its hash covers.
 */
function ageEntries(string $stream, int $from, int $to, string $at): void
{
    DB::table(auditsTable())
        ->where('stream', $stream)
        ->whereBetween('sequence', [$from, $to])
        ->update(['created_at' => $at]);
}

function pruner(): Pruner
{
    /** @var Pruner $pruner */
    $pruner = app(Pruner::class);

    return $pruner;
}

/**
 * A batch of freshly written entries, already on the fake disk.
 *
 * @param  array<string, mixed>  $overrides
 */
function archivedBatch(int $count = 2, array $overrides = []): ArchiveBatch
{
    $entries = [];

    foreach (range(1, $count) as $ignored) {
        $entries[] = ledger()->write(auditData($overrides));
    }

    return batchWriter()->write('global', 1, $count, $entries, [], '2026-08-31 10:00:00.000000');
}

function archiveLedger(): ArchiveLedger
{
    /** @var ArchiveLedger $ledger */
    $ledger = app(ArchiveLedger::class);

    return $ledger;
}

function batchReader(): BatchReader
{
    /** @var BatchReader $reader */
    $reader = app(BatchReader::class);

    return $reader;
}

function batchWriter(): BatchWriter
{
    /** @var BatchWriter $writer */
    $writer = app(BatchWriter::class);

    return $writer;
}

/**
 * A writer over a disk that misbehaves in one named way. The Filesystem contract has twenty-three
 * methods and this needs two of them, so it is a double rather than a fixture implementing all of
 * it — the same call Mockery already answers in the two gate tests.
 */
function writerOverDisk(Filesystem $disk): BatchWriter
{
    $disks = Mockery::mock(Factory::class);
    $disks->shouldReceive('disk')->andReturn($disk);

    return new BatchWriter(
        $disks,
        new JsonCanonicalizer,
        hasher(),
        new Content(hasher()),
        new Audit,
        sentinelConfig(),
    );
}

/**
 * A batch written by hand at a chosen container format, with a checksum that matches its bytes — the
 * only way to reach the format guard, which sits behind the digest.
 */
function batchAtFormat(int $format): ArchiveBatch
{
    $body = json_encode(['kind' => 'batch', 'format' => $format, 'stream' => 'global',
        'sequence_from' => 1, 'sequence_to' => 1, 'records' => 0, 'written_at' => '2026-08-31 10:00:00.000000'])."\n";

    Storage::disk('cold')->put('sentinel/forged.ndjson', $body);

    return new ArchiveBatch('global', 1, 1, 0, 'cold', 'sentinel/forged.ndjson',
        'sha256:'.hash('sha256', $body), null);
}

function rehydrator(): Rehydrator
{
    /** @var Rehydrator $rehydrator */
    $rehydrator = app(Rehydrator::class);

    return $rehydrator;
}

function archiver(): Archiver
{
    /** @var Archiver $archiver */
    $archiver = app(Archiver::class);

    return $archiver;
}

function cascade(): Cascade
{
    /** @var Cascade $cascade */
    $cascade = app(Cascade::class);

    return $cascade;
}

/**
 * @param  array<string, string>  $retention
 */
function frontiers(array $retention): Frontiers
{
    sentinelConfig(['retention' => $retention]);

    app()->forgetScopedInstances();

    /** @var Frontiers $frontiers */
    $frontiers = app(Frontiers::class);

    return $frontiers;
}

/**
 * @param  array<string, string>  $retention
 */
function retentionSchedule(array $retention): Schedule
{
    return new Schedule(sentinelConfig(['retention' => $retention]));
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

/**
 * One entry, written straight to the table with only the columns the schema requires. It is what a
 * retention expectation is built from: nothing here goes through the ledger, so the clock and the
 * kind of entry are exactly what the test says they are.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedAudit(int $sequence, array $overrides = []): void
{
    DB::table(auditsTable())->insert(new Audit()->forceFill([
        'id' => str_pad('01JSEED'.$sequence, 26, '0'),
        'stream' => 'global',
        'sequence' => $sequence,
        'audit_type' => 'model',
        'event' => 'created',
        'severity' => 'info',
        'source' => 'system',
        'context' => [],
        'payload_version' => 1,
        'algorithm' => 'sha256',
        'hash' => str_pad((string) $sequence, 64, 'a'),
        'occurred_at' => '2026-08-30 12:00:00.000000',
        'created_at' => '2026-08-30 12:00:00.000000',
        ...$overrides,
    ])->getAttributes());
}

/**
 * @param  array<string, string>  $retention
 * @return list<int>
 */
function keptBy(array $retention, string $now = '2026-08-30 12:00:00'): array
{
    $entries = DB::table(auditsTable());

    new RetainedPredicate(retentionSchedule($retention))->applyTo($entries, new CarbonImmutable($now));

    /** @var list<int> $kept */
    $kept = $entries->orderBy('sequence')->pluck('sequence')->all();

    return array_map(intval(...), $kept);
}

function retireEntries(string $stream, int $from, int $to): int
{
    return DB::table(auditsTable())
        ->where('stream', $stream)
        ->whereBetween('sequence', [$from, $to])
        ->delete();
}

function manifest(): Manifest
{
    /** @var Manifest $manifest */
    $manifest = app(Manifest::class);

    return $manifest;
}

function archivesTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('archives');
}

function checkpointsTable(): string
{
    /** @var Config $config */
    $config = app(Config::class);

    return $config->table('checkpoints');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkpointRow(array $overrides = []): array
{
    return [
        'id' => frozenUlid('ANCH'),
        'stream' => 'global',
        'sequence_from' => 1,
        'sequence_to' => 4,
        'root_hash' => str_repeat('a', 64),
        'algorithm' => 'fold-sha256',
        'signature' => null,
        'key_id' => null,
        'created_at' => '2026-08-30 09:00:00.000000',
        ...$overrides,
    ];
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

function fold(): Fold
{
    return new Fold(hasher());
}

/**
 * The hashes of one range of a stream, read through the Ledger contract so the same call works over
 * a table and over an array.
 *
 * @return list<string>
 */
function hashesOf(Ledger $ledger, string $stream, int $from, ?int $to = null): array
{
    $hashes = [];

    foreach ($ledger->stream($stream)->range($from, $to) as $audit) {
        $hashes[] = $audit->hash;
    }

    return $hashes;
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

/**
 * @return list<array<string, mixed>>
 */
function referenceChainOf(string $stream): array
{
    return array_values(array_filter(
        ReferenceChain::entries(),
        static fn (array $entry): bool => $entry['stream'] === $stream,
    ));
}

/**
 * A real chain of the given length, sealed by the ledger itself so every link is the hash of the
 * entry before it. Long enough that walking it pages more than once, which is what makes the cost
 * of walking it and the cost of walking its anchors tell apart.
 */
function seedTheLongChain(int $entries): void
{
    $audits = [];

    for ($entry = 1; $entry <= $entries; $entry++) {
        $audits[] = auditData();
    }

    if ($audits !== []) {
        ledger()->writeMany($audits);
    }
}

/**
 * The reference chain over a ledger with no table under it, so a range of it can be folded without
 * seeding anything.
 */
function referenceChainInMemory(): MemoryLedger
{
    /** @var MemoryLedger $ledger */
    $ledger = app(MemoryLedger::class);

    foreach (ReferenceChain::entries() as $entry) {
        $ledger->append(new Audit()->forceFill($entry));
    }

    return $ledger;
}

/**
 * @return list<string>
 */
function referenceHashes(string $stream, int $from, ?int $to = null): array
{
    return hashesOf(referenceChainInMemory(), $stream, $from, $to);
}

/**
 * The reference chain, put in the table exactly as it was frozen. Unlike the golden trail this one
 * links: it is seeded whole, both streams, so a test can break one row and watch the walk stop
 * there.
 */
function seedTheReferenceChain(): void
{
    $at = new CarbonImmutable('2026-08-29 09:00:00');

    foreach (ReferenceChain::entries() as $attributes) {
        DB::table(auditsTable())->insert(
            new Audit()->forceFill([...$attributes, 'created_at' => $at])->getAttributes(),
        );

        $at = $at->addSecond();
    }
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

function hashToSign(): string
{
    return hash('sha256', 'an entry');
}

/**
 * The three implementations, each with whether it produces a signature at all. Every expectation
 * the contract makes has to hold for all three, and the one that signs nothing is the reason the
 * shape of that expectation is a pair rather than a signer.
 *
 * @return array<string, array{Signer, bool}>
 */
function signers(): array
{
    return [
        'hmac' => [new HmacSigner('v1', SigningKeys::SECRET, 'sha256'), true],
        'openssl' => [new OpenSslSigner('v1', SigningKeys::PUBLIC_KEY, SigningKeys::PRIVATE_KEY, 'sha256'), true],
        'null' => [new NullSigner, false],
    ];
}

function signingWith(string $keyId, string $secret): void
{
    config()->set('sentinel.integrity.signature.enabled', true);
    config()->set('sentinel.integrity.signature.keys', [$keyId => $secret]);
    config()->set('sentinel.integrity.signature.key_id', $keyId);
}

function projections(): Projections
{
    /** @var Projections $projections */
    $projections = app(Projections::class);

    return $projections;
}

function checkpoints(): Checkpoints
{
    /** @var Checkpoints $checkpoints */
    $checkpoints = app(Checkpoints::class);

    return $checkpoints;
}

/**
 * Anchors of the given size, over a stream already in the table.
 *
 * @return list<Checkpoint>
 */
function anchor(string $stream, int $every): array
{
    config()->set('sentinel.integrity.checkpoints.every', $every);

    return checkpoints()->issue($stream);
}

function signerRing(): Signers
{
    /** @var Signers $ring */
    $ring = app(Signers::class);

    return $ring;
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
 * Writes the anchor an emitter is about to write, in the moment between its read of where the
 * anchors end and its own insert — which is the one moment the unique index has to arbitrate.
 * Interfering once is a rival that got there first; interfering every time is the same rival
 * winning every race there is.
 */
function anchorAheadOf(string $stream, int $times): void
{
    $done = 0;

    DB::listen(function (QueryExecuted $query) use (&$done, $times, $stream): void {
        if ($done >= $times || ! str_contains($query->sql, checkpointsTable()) || ! str_contains($query->sql, 'order by')) {
            return;
        }

        $done++;

        DB::table(checkpointsTable())->insert(checkpointRow([
            'id' => frozenUlid('RIVAL'.$done),
            'stream' => $stream,
        ]));
    });
}

/**
 * Slips a rival emitter in between the read of where the anchors end and the insert of the next
 * one. Whether it gets through is the engine's business; that the anchors stay contiguous either
 * way is not.
 */
function raceTheAnchor(string $rival): void
{
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $rival): void {
        if ($raced || ! str_contains($query->sql, checkpointsTable())) {
            return;
        }

        $raced = true;

        rescue(function () use ($rival): void {
            DB::connection($rival)->statement(lockTimeout());
            DB::connection($rival)->table(checkpointsTable())->insert(checkpointRow());
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
 * Rows put in the table behind the model's back. Nothing about a mass operation is about model
 * events, and seeding through the model would write an entry per row before the test began.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedSubjects(int $count, array $overrides = []): void
{
    $rows = [];

    for ($at = 1; $at <= $count; $at++) {
        $rows[] = [
            'name' => 'subject '.$at,
            'email' => "subject{$at}@example.com",
            'status' => 'draft',
            'active' => true,
            ...$overrides,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('fixture_audited_subjects')->insert($chunk);
    }
}

/**
 * The entries a mass operation left, oldest first.
 *
 * @return list<Audit>
 */
function massEntries(): array
{
    /** @var list<Audit> $entries */
    $entries = Audit::query()->where('audit_type', 'mass')->orderBy('id')->get()->all();

    return $entries;
}

/**
 * @param  Closure(): mixed  $work
 */
function statementsDuring(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getRawQueryLog());

    DB::disableQueryLog();

    return $count;
}

/**
 * An entry whose criteria holds these clauses and nothing else, which is the shape the mass
 * serialiser produces and the one the protected walk has to recognise.
 *
 * @param  list<array<string, mixed>>  $wheres
 * @return array<string, mixed>
 */
function searchedFor(array $wheres): array
{
    return ['criteria' => ['wheres' => $wheres]];
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

function redactor(): Redactor
{
    /** @var Redactor $redactor */
    $redactor = app(Redactor::class);

    return $redactor;
}

/**
 * Which of the published stubs divides the trail on the engine this pass is running against.
 * SQLite has no answer: it does not partition, which is why the tests that need one skip there.
 */
function divisionForThisEngine(): ?string
{
    return match (DB::connection()->getDriverName()) {
        'pgsql' => 'pgsql-range',
        'mysql' => 'mysql-range',
        default => null,
    };
}

/**
 * The trail, rebuilt under one of the published partitioned stubs. The base migration has already
 * run by the time a test asks for this, so the table goes and comes back divided — and the
 * occurrence indexes, which live in a migration of their own that a stub does not replace, are put
 * back the way a real installation would still have them.
 */
function partitionTheTrail(string $division): void
{
    Schema::dropIfExists(auditsTable());

    migrationIn("stubs/partitioned/{$division}")->up();
    migrationIn('migrations', 'add_occurrence_indexes')->up();
}

/**
 * One migration file, loaded from wherever the package keeps it.
 */
function migrationIn(string $directory, string $matching = ''): Migration
{
    $files = glob(dirname(__DIR__)."/database/{$directory}/*{$matching}*.php");

    /** @var Migration $migration */
    $migration = require is_array($files) && $files !== []
        ? $files[0]
        : throw new RuntimeException("No migration matching [{$matching}] in [{$directory}].");

    return $migration;
}

/**
 * The published index migration, loaded against whichever engine the suite is on. It is not loaded
 * automatically — that is the point of it — so a test that wants the index asks for it here.
 */
function jsonIndexMigration(): Migration
{
    $files = glob(dirname(__DIR__).'/database/stubs/json-indexes/*.php');

    /** @var Migration $migration */
    $migration = require is_array($files) && $files !== []
        ? $files[0]
        : throw new RuntimeException('The json-indexes stub is missing.');

    return $migration;
}
