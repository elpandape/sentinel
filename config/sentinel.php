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
            // Keeps every entry on the instance and nothing past it: a reference
            // implementation and a test double, never a store.
            'memory' => [],
            'null' => [],
            /*
             * One entry, several destinations. The first is the primary: it assigns the
             * sequence and seals the hash, and the rest are handed what it sealed. Under
             * strict a destination refusing the entry fails the write; under primary only
             * the first one does, and the rest raise LedgerDestinationFailed.
             */
            'fanout' => [
                'destinations' => ['database'],
                'on_failure' => 'strict',
            ],
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
        ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
        ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
        ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
    ],

    /*
     * Hidden attributes are audited by default: auditing is what the package is
     * for. Turning include_hidden off drops them from every snapshot.
     */
    'snapshots' => [
        'enabled' => true,
        'include_hidden' => true,
    ],

    /*
     * What never reaches the ledger in the clear. A model declares its own fields with
     * $auditRedact, $auditEncrypt and $auditHash; the lists here add to those and are the
     * only way to name a key no model owns, like an address or a console argument.
     */
    'security' => [
        'redaction' => [
            'mask' => '*',
            'fields' => [],
            // Default masker for every field, and overrides keyed by field name.
            'masker' => null,
            'maskers' => [],
        ],
        'encryption' => [
            'cipher' => 'aes-256-gcm',
            // The key every new entry is written with. Older entries keep the identifier
            // they recorded, so rotating this leaves them readable as long as their key stays.
            'key_id' => 'default',
            'keys' => [
                'default' => env('SENTINEL_ENCRYPTION_KEY'),
            ],
            'fields' => [],
        ],
        'hashing' => [
            'algorithm' => 'sha256',
            // Derived from APP_KEY when null. Stable by definition: rotating it keeps the
            // chain intact and destroys the comparability of every digest written before.
            'salt' => env('SENTINEL_HASH_SALT'),
            'fields' => [],
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
            'rekeyed' => 'notice',
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
