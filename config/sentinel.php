<?php

declare(strict_types=1);

return [

    'enabled' => env('SENTINEL_ENABLED', true),

    /*
     * How audits reach the ledger: sync writes them in the request, queue
     * dispatches a job, buffered accumulates them and flushes in batches.
     */
    'mode' => env('SENTINEL_MODE', 'sync'),

    'queue' => [
        'connection' => env('SENTINEL_QUEUE_CONNECTION'),
        'queue' => env('SENTINEL_QUEUE'),
    ],

    'buffer' => [
        'store' => env('SENTINEL_BUFFER_STORE', 'redis'),
        'key' => 'sentinel:buffer',
        'size' => 500,
        'flush_interval' => 60,
    ],

    'ledger' => [
        'default' => env('SENTINEL_LEDGER', 'database'),
        'ledgers' => [
            'database' => [],
            'archive' => [
                'disk' => env('SENTINEL_ARCHIVE_DISK', 'local'),
                'path' => 'sentinel',
                'compress' => true,
            ],
            'null' => [],
        ],
    ],

    'database' => [
        // Dedicated connection for audits. Null uses the default.
        'connection' => env('SENTINEL_DB_CONNECTION'),
    ],

    'tables' => [
        'prefix' => 'sentinel_',
        'audits' => 'audits',
        'audit_tags' => 'audit_tags',
        'audit_relations' => 'audit_relations',
        'transactions' => 'transactions',
        'checkpoints' => 'checkpoints',
        'archives' => 'archives',
        'access_log' => 'access_log',
    ],

    /*
     * Model overrides. Null means the package default; any subclass of it
     * replaces the model the container resolves.
     */
    'models' => [
        'audit' => null,
    ],

    /*
     * Context resolvers, one entry each. A null class means the package default;
     * any class implementing Contracts\Resolver replaces it. Every key here also
     * has a default in code, so a config published before they existed still boots.
     */
    'resolvers' => [
        'actor' => ['class' => null, 'guard' => null],
        'impersonator' => ['class' => null, 'session_key' => 'impersonated_by'],
        'tenant' => ['class' => null, 'using' => null],
        'request' => ['class' => null, 'header' => 'X-Request-Id', 'api' => 'api/*'],
        'session' => ['class' => null],
        'trace' => ['class' => null],
        'source' => ['class' => null],
        'host' => ['class' => null],
        'job' => ['class' => null],
        'command' => ['class' => null, 'redact' => ['password', 'token', 'secret']],
    ],

    /*
     * Stages every audit goes through before it reaches the ledger. Order is
     * the list order; a stage returning null discards the audit. An empty list
     * means this one: dropping a stage means declaring the list without it.
     */
    'pipeline' => [
        ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
        ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
        ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ],

    /*
     * Hidden attributes are audited by default: auditing is what the package is
     * for. Turning include_hidden off drops them from every snapshot.
     */
    'snapshots' => [
        'enabled' => true,
        'include_hidden' => true,
    ],

    'security' => [
        'redaction' => [
            'mask' => '*',
        ],
        'encryption' => [
            'enabled' => false,
            'key_id' => 'default',
        ],
        'hashing' => [
            'algorithm' => 'sha256',
            'salt' => env('SENTINEL_HASH_SALT'),
        ],
    ],

    /*
     * The tamper-evident chain. Streams scope it: global, tenant, subject_type
     * or a closure. Checkpoints anchor ranges so verification stays cheap.
     */
    'integrity' => [
        'enabled' => false,
        'algorithm' => 'sha256',
        'stream' => 'tenant',
        'checkpoints' => [
            'enabled' => false,
            'every' => 1000,
        ],
        'signature' => [
            'enabled' => false,
            'signer' => null,
            'key_id' => 'default',
        ],
    ],

    'severity' => [
        'default' => 'info',
        'events' => [
            'deleted' => 'notice',
            'force_deleted' => 'warning',
        ],
    ],

    'tags' => [
        'enabled' => true,
    ],

    'transactions' => [
        // Defer audits until the database transaction commits, so a rollback
        // leaves no record of what never happened.
        'after_commit' => true,
    ],

    'mass_operations' => [
        'mode' => 'summary',
        'threshold' => 100,
    ],

    /*
     * Retention policies keyed by logical audit type or by subject class,
     * e.g. 'model:App\Models\User' => '7 years'.
     */
    'retention' => [],

    /*
     * Compliance mode makes audits immutable, requires the integrity chain and
     * signatures, forces archiving before pruning, and logs every read.
     */
    'compliance' => false,

    'telemetry' => [
        'enabled' => false,
        'service_name' => env('APP_NAME', 'laravel'),
    ],

];
