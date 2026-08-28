<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('reads the table and the connection the configuration names', function (): void {
    config()->set('sentinel.tables.prefix', 'audit_');
    config()->set('sentinel.tables.audit_tags', 'labels');
    config()->set('sentinel.database.connection', 'secondary');

    expect(new AuditTag()->getTable())->toBe('audit_labels')
        ->and(new AuditTag()->getConnectionName())->toBe('secondary');
});

it('hands back the labels of an entry in a stable order', function (): void {
    insertAudit(['id' => '01J0000000000000000000TAGS', 'sequence' => 1]);

    DB::table(auditTagsTable())->insert([
        ['audit_id' => '01J0000000000000000000TAGS', 'tag' => 'refund'],
        ['audit_id' => '01J0000000000000000000TAGS', 'tag' => 'billing'],
    ]);

    $audit = Audit::query()->findOrFail('01J0000000000000000000TAGS');

    expect($audit->tags->pluck('tag')->all())->toBe(['billing', 'refund']);
});

it('hands back nothing for an entry nobody classified', function (): void {
    insertAudit(['id' => '01J000000000000000000BARE', 'sequence' => 1]);

    expect(Audit::query()->findOrFail('01J000000000000000000BARE')->tags)->toBeEmpty();
});

it('keeps a label off the attributes that make up the entry', function (): void {
    insertAudit(['id' => '01J0000000000000000000HASH', 'sequence' => 1]);
    DB::table(auditTagsTable())->insert(['audit_id' => '01J0000000000000000000HASH', 'tag' => 'billing']);

    $audit = Audit::query()->findOrFail('01J0000000000000000000HASH');
    $audit->load('tags');

    expect($audit->getAttributes())->not->toHaveKey('tags');
});
