<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;

/**
 * MySQL and PostgreSQL both reorder the keys of a JSON object on the way in, so a line published
 * as the column handed it back would be a different shape on each of the three engines the chain
 * is verified on. The rows here arrive already reordered, which is what those engines do to them.
 */
it('publishes a relation line in the order the package fixed, not the one it was stored in', function (): void {
    insertAudit(['id' => frozenUlid('SHUFFLE'), 'sequence' => 1, 'audit_type' => 'relation', 'event' => 'synced']);

    DB::table(auditsTable())->where('id', frozenUlid('SHUFFLE'))->update([
        'changes' => json_encode([[
            'pivot_after' => ['role' => 'owner', 'added_by' => 'ada'],
            'related_id' => '3',
            'operation' => 'attach',
            'relation' => 'members',
            'pivot_before' => null,
            'related_type' => 'member',
        ]], JSON_THROW_ON_ERROR),
    ]);

    $line = Audit::query()->findOrFail(frozenUlid('SHUFFLE'))->toArray()['changes'][0];

    expect(array_keys($line))->toBe(['relation', 'operation', 'related_type', 'related_id', 'pivot_before', 'pivot_after'])
        ->and(array_keys($line['pivot_after']))->toBe(['added_by', 'role']);
});

it('keeps a key the package does not know behind the ones it does', function (): void {
    insertAudit(['id' => frozenUlid('EXTRA'), 'sequence' => 1, 'audit_type' => 'relation', 'event' => 'synced']);

    DB::table(auditsTable())->where('id', frozenUlid('EXTRA'))->update([
        'changes' => json_encode([[
            'relation' => 'members',
            'operation' => 'attach',
            'zeta' => 1,
            'alpha' => 2,
        ]], JSON_THROW_ON_ERROR),
    ]);

    $line = Audit::query()->findOrFail(frozenUlid('EXTRA'))->toArray()['changes'][0];

    expect(array_keys($line))->toBe([
        'relation', 'operation', 'related_type', 'related_id', 'pivot_before', 'pivot_after', 'alpha', 'zeta',
    ]);
});

/**
 * toEqual and not toBe: a column the package did not write goes back as the engine handed it over,
 * key order included, and that is the only honest answer for it. What the package writes it also
 * orders, and the test below is the one that holds it to that.
 */
it('serialises a row whose changes it cannot read rather than refusing the whole trail', function (mixed $poison): void {
    insertAudit(['id' => frozenUlid('UNREAD'), 'sequence' => 1]);

    DB::table(auditsTable())->where('id', frozenUlid('UNREAD'))->update([
        'changes' => json_encode($poison, JSON_THROW_ON_ERROR),
    ]);

    expect(Audit::query()->findOrFail(frozenUlid('UNREAD'))->toArray()['changes'])->toEqual($poison);
})->with([
    'the map by field an early version wrote' => [['name' => ['José', 'Grace']]],
    'an operation the diff does not have' => [[['path' => '/name', 'op' => 'shrug', 'new' => 'Grace']]],
    'an entry with no new value' => [[['path' => '/name', 'op' => 'replace']]],
]);

it('publishes a diff entry in the order the package fixed, not the one it was stored in', function (): void {
    insertAudit(['id' => frozenUlid('DIFFORD'), 'sequence' => 1]);

    DB::table(auditsTable())->where('id', frozenUlid('DIFFORD'))->update([
        'changes' => json_encode([
            ['new' => 'Grace', 'op' => 'replace', 'path' => '/name', 'old' => 'Ada'],
        ], JSON_THROW_ON_ERROR),
    ]);

    $entry = Audit::query()->findOrFail(frozenUlid('DIFFORD'))->toArray()['changes'][0];

    expect(array_keys($entry))->toBe(['path', 'op', 'old', 'new']);
});

it('publishes the labels in an order no collation decides', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData(['tags' => ['Refund', 'billing', 'Audit']]));

    expect($audit->fresh()?->toArray()['tags'])->toBe(['Audit', 'Refund', 'billing']);
});
