<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\transactionRow;
use function ElPandaPe\Sentinel\Tests\transactionsTable;

it('reads the table and the connection the configuration names', function (): void {
    config()->set('sentinel.tables.prefix', 'audit_');
    config()->set('sentinel.tables.transactions', 'operations');
    config()->set('sentinel.database.connection', 'secondary');

    expect(new AuditTransaction()->getTable())->toBe('audit_operations')
        ->and(new AuditTransaction()->getConnectionName())->toBe('secondary');
});

it('gives the metadata back as a map, not as text', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('META'),
        'metadata' => json_encode(['nested' => ['invoice-lines'], 'failed' => false]),
    ]));

    $header = AuditTransaction::query()->findOrFail(frozenUlid('META'));

    expect($header->metadata)->toBe(['nested' => ['invoice-lines'], 'failed' => false]);
});

it('keeps the microseconds the schema declares', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('CLOK'),
        'started_at' => '2026-08-28 10:00:00.123456',
    ]));

    $header = AuditTransaction::query()->findOrFail(frozenUlid('CLOK'));

    expect($header->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($header->started_at->format('u'))->toBe('123456');
});

it('leaves finished_at null while the operation is still running', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('LIVE'),
        'finished_at' => null,
    ]));

    expect(AuditTransaction::query()->findOrFail(frozenUlid('LIVE'))->finished_at)->toBeNull();
});

it('walks from the operation to the entries it wrote', function (): void {
    DB::table(transactionsTable())->insert(transactionRow(['id' => frozenUlid('WALK')]));

    insertAudit(['id' => frozenUlid('ONE'), 'sequence' => 1, 'transaction_id' => frozenUlid('WALK')]);
    insertAudit(['id' => frozenUlid('TWO'), 'sequence' => 2, 'transaction_id' => frozenUlid('WALK')]);
    insertAudit(['id' => frozenUlid('OUT'), 'sequence' => 3]);

    $header = AuditTransaction::query()->findOrFail(frozenUlid('WALK'));

    expect($header->audits->map(static fn (Audit $audit): string => $audit->id)->all())
        ->toBe([frozenUlid('ONE'), frozenUlid('TWO')]);
});

it('hands back nothing for an operation that wrote no entry', function (): void {
    DB::table(transactionsTable())->insert(transactionRow(['id' => frozenUlid('BARE')]));

    expect(AuditTransaction::query()->findOrFail(frozenUlid('BARE'))->audits)->toBeEmpty();
});

it('takes every column on creation and names itself with a ulid', function (): void {
    $header = AuditTransaction::query()->create([
        'name' => 'invoice-payment',
        'actor_type' => 'user',
        'actor_id' => '7',
        'tenant_id' => 'acme',
        'started_at' => new CarbonImmutable('2026-08-28 10:00:00.000000'),
        'metadata' => ['nested' => []],
    ]);

    expect($header->id)->toHaveLength(26)
        ->and($header->audits_count)->toBe(0)
        ->and(AuditTransaction::query()->findOrFail($header->id)->actor_id)->toBe('7');
});

it('can be completed, unlike the entries it heads', function (): void {
    DB::table(transactionsTable())->insert(transactionRow(['id' => frozenUlid('SHUT')]));

    $header = AuditTransaction::query()->findOrFail(frozenUlid('SHUT'));
    $header->finished_at = new CarbonImmutable('2026-08-28 10:00:02.000000');
    $header->audits_count = 2;
    $header->save();

    expect(AuditTransaction::query()->findOrFail(frozenUlid('SHUT'))->audits_count)->toBe(2);
});
