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

    /*
     * Where entries wait under the buffered mode. The store names the driver: redis is the one the
     * mode is for, and memory keeps everything on the instance — a reference implementation and a
     * test double, never a store. A connection of null uses the application's default Redis one.
     *
     * The two thresholds bound how much can be lost, and they are evaluated when an entry arrives:
     * nothing in PHP is watching the clock between requests. What bounds a buffer nobody is writing
     * to is the flush at the end of the request, the one at worker shutdown, and sentinel:flush.
     */
    'buffer' => [
        'store' => env('SENTINEL_BUFFER_STORE', 'redis'),
        'connection' => env('SENTINEL_BUFFER_CONNECTION'),
        'key' => 'sentinel:buffer',
        'size' => 500,
        'flush_interval' => 60,
    ],

    /*
     * What a write that did not complete does to the request that caused it: throw propagates the
     * failure, log records it through the channel below and lets the request through. One default
     * and not one per environment, because a policy that differs between them is a policy nobody
     * has tested. Compliance forces throw regardless: a ledger that can lose entries in silence
     * proves nothing.
     *
     * It governs the write that happens in the request. One deferred to a commit cannot propagate
     * whatever this says — by then the transaction has committed, and an exception out of it would
     * report a failure of something that succeeded — so a deferred failure is always announced and
     * recorded instead.
     */
    'on_write_failure' => env('SENTINEL_ON_WRITE_FAILURE', 'throw'),

    // The channel a recorded failure is written through. Null uses the application default.
    'log_channel' => env('SENTINEL_LOG_CHANNEL'),

    'ledger' => [
        'default' => env('SENTINEL_LEDGER', 'database'),
        'ledgers' => [
            'database' => [],
            /*
             * Cold storage: NDJSON, one batch per file, on any disk the application configures.
             * codec names how the bytes are written — gzip, which needs ext-zlib, or null for plain
             * text. It is a name and not a flag because the manifest records it, and a boolean could
             * never say what to inflate a batch written two years ago with.
             */
            'archive' => [
                'disk' => env('SENTINEL_ARCHIVE_DISK', 'local'),
                'path' => 'sentinel',
                'codec' => 'gzip',
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
        'transaction' => null,
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
        ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
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
            'signer' => 'hmac',
            'algorithm' => 'sha256',
            // The key every new entry is signed with. Older entries keep the identifier they
            // recorded, so rotating this leaves them verifiable as long as their key stays below.
            'key_id' => 'default',
            // What each identifier VERIFIES with: the shared secret under hmac, the public key
            // under openssl. It is the half an external auditor is given, and holding it is not
            // enough to sign anything. Derived from APP_KEY when 'default' is left null.
            'keys' => [
                'default' => env('SENTINEL_SIGNING_KEY'),
            ],
            // What the current identifier SIGNS with, under openssl only: the private key, which
            // a verifying node does not need and should not have. Unused by hmac, where one
            // secret does both.
            'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),
        ],
    ],

    'severity' => [
        'default' => 'info',
        'events' => [
            'deleted' => 'notice',
            'force_deleted' => 'warning',
            'rekeyed' => 'notice',
            // Getting in is routine; being refused or shut out is not.
            'failed' => 'warning',
            'lockout' => 'critical',
            'password_reset' => 'notice',
        ],
    ],

    'tags' => [
        'enabled' => true,

        // Labels every entry is born with, on top of whatever the model declares.
        'default' => [],
    ],

    'transitions' => [
        // The column a state change is about when neither the call nor the model names one.
        'attribute' => 'status',
    ],

    'transactions' => [
        // Defer audits until the database transaction commits, so a rollback
        // leaves no record of what never happened.
        'after_commit' => true,
    ],

    /*
     * What a query that asked for it with auditing() writes down. summary is one entry for the
     * whole operation, individual is one per row with its real before, and hybrid is summary plus
     * individuals while the set stays under the threshold. summary is the default and the only one
     * whose cost does not grow with the size of the set.
     *
     * sample bounds what a long set leaves behind: a whereIn over five thousand identifiers records
     * the count and this many of them, never the list.
     */
    'mass_operations' => [
        'mode' => 'summary',
        'threshold' => 100,
        'sample' => 20,
    ],

    /*
     * Retention policies keyed by logical audit type or by subject class,
     * e.g. 'model:App\Models\User' => '7 years'.
     */
    'retention' => [],

    /*
     * What one run of sentinel:prune is allowed to do. windows is how many anchored ranges it will
     * look at — a range a long policy holds is re-examined on every run, and this is what keeps that
     * from growing without bound. batch is how many entries one statement removes, and pause is how
     * many microseconds it waits between two of them, so a purge does not compete with the writes it
     * is making room for.
     *
     * The batch is bounded on purpose: an unbounded delete over a table this size is how the prior
     * art ends up hanging for an hour. It is named by sequence rather than by a LIMIT, so the three
     * engines compile one plan instead of three and an interrupted run resumes by arithmetic.
     */
    'prune' => [
        'windows' => 100,
        'batch' => 1000,
        'pause' => 0,
    ],

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
