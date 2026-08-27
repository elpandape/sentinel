<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

function verifier(): Verifier
{
    /** @var Verifier $verifier */
    $verifier = app(Verifier::class);

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
