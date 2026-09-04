<?php

declare(strict_types=1);

/**
 * A starting point for moving an application off altek/accountant.
 *
 * It renames imports and fully-qualified names and does nothing else. Every behavioural difference
 * in MIGRATE_FROM_ALTEK.md is yours to make by hand.
 *
 *     cp vendor/elpandape/sentinel/stubs/rector/altek.php rector-sentinel.php
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
        'Altek\Accountant\Recordable' => 'ElPandaPe\Sentinel\Concerns\Auditable',
        'Altek\Accountant\Models\Ledger' => 'ElPandaPe\Sentinel\Models\Audit',
        'Altek\Accountant\Contracts\Ledger' => 'ElPandaPe\Sentinel\Models\Audit',
        'Altek\Accountant\Contracts\LedgerDriver' => 'ElPandaPe\Sentinel\Contracts\Ledger',
        'Altek\Accountant\Drivers\Database' => 'ElPandaPe\Sentinel\Ledger\DatabaseLedger',
    ]);

/*
 * What this deliberately does NOT rename, because there is nothing to rename it to:
 *
 * Altek\Accountant\Contracts\Recordable — Sentinel's trait implements no interface. Delete the
 *     `implements` clause by hand.
 *
 * Altek\Accountant\Contracts\Cipher and the two bundled ciphers — the equivalents are
 *     $auditEncrypt, $auditRedact and $auditHash, which are per-field settings rather than
 *     classes. See *The write pipeline* in the README.
 *
 * Altek\Accountant\Context — Sentinel records where a write came from and never uses it to decide
 *     whether to record. There is no bitmask to carry over.
 *
 * Altek\Accountant\Notary — signing is configuration in Sentinel, not a class you name.
 */
