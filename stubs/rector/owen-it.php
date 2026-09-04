<?php

declare(strict_types=1);

/**
 * A starting point for moving an application off owen-it/laravel-auditing.
 *
 * It renames imports and fully-qualified names and does nothing else. Every behavioural difference
 * in MIGRATE_FROM_OWEN_IT.md is yours to make by hand — a tool that tried would be a tool guessing
 * at what your audit trail is supposed to mean.
 *
 *     cp vendor/elpandape/sentinel/stubs/rector/owen-it.php rector-sentinel.php
 *     vendor/bin/rector process --config=rector-sentinel.php --dry-run
 *
 * Run it in dry run until you have read every hunk. The paths below are the ones a Laravel
 * application usually keeps its models in; widen them to yours.
 */

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
    ])
    ->withConfiguredRule(RenameClassRector::class, [
        'OwenIt\Auditing\Auditable' => 'ElPandaPe\Sentinel\Concerns\Auditable',
        'OwenIt\Auditing\Models\Audit' => 'ElPandaPe\Sentinel\Models\Audit',
        'OwenIt\Auditing\Contracts\Audit' => 'ElPandaPe\Sentinel\Models\Audit',
        'OwenIt\Auditing\Contracts\AuditDriver' => 'ElPandaPe\Sentinel\Contracts\Ledger',
        'OwenIt\Auditing\Drivers\Database' => 'ElPandaPe\Sentinel\Ledger\DatabaseLedger',
        'OwenIt\Auditing\Contracts\Resolver' => 'ElPandaPe\Sentinel\Contracts\Resolver',
        'OwenIt\Auditing\Contracts\UserResolver' => 'ElPandaPe\Sentinel\Contracts\Resolver',
    ]);

/*
 * What this deliberately does NOT rename, because there is nothing to rename it to:
 *
 * OwenIt\Auditing\Contracts\Auditable — Sentinel's trait implements no interface. Delete the
 *     `implements` clause by hand.
 *
 * OwenIt\Auditing\Events\* — the lifecycle is different in shape, not only in name. Read
 *     *Lifecycle events* in the README and rewrite the listeners.
 *
 * OwenIt\Auditing\Auditor and the Auditor facade — Sentinel's entry point is the Sentinel facade,
 *     and it answers different questions.
 */
