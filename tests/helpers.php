<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests;

use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
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
    });

    Schema::create('fixture_ulid_subjects', static function (Blueprint $table): void {
        $table->ulid('id')->primary();
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
