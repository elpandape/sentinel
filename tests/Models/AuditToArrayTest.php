<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

use function ElPandaPe\Sentinel\Tests\auditData;

it('says who did what to what, and on whose behalf', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'updated',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '100',
        'impersonator_type' => 'App\\Models\\Administrator',
        'impersonator_id' => '1',
    ]));

    expect($audit->toArray())
        ->toHaveKey('subject', ['type' => 'App\\Models\\Invoice', 'id' => '500'])
        ->toHaveKey('actor', ['type' => 'App\\Models\\User', 'id' => '100'])
        ->toHaveKey('impersonator', ['type' => 'App\\Models\\Administrator', 'id' => '1'])
        ->toHaveKey('event', 'updated');
});

it('says nothing rather than half a reference when one was never recorded', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect($audit->toArray())
        ->toHaveKey('subject', null)
        ->toHaveKey('actor', null)
        ->toHaveKey('impersonator', null);
});

it('keeps the pointer list the column holds', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'changes' => [['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']],
    ]));

    expect($audit->toArray()['changes'])
        ->toBe([['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']]);
});

it('tells a diff that found nothing from an entry that had none to make', function (): void {
    $none = app(DatabaseLedger::class)->write(auditData());
    $empty = app(DatabaseLedger::class)->write(auditData(['changes' => []]));

    expect($none->toArray()['changes'])->toBeNull()
        ->and($empty->toArray()['changes'])->toBe([]);
});

it('always says what an entry is labelled, even when it is nothing', function (): void {
    $labelled = app(DatabaseLedger::class)->write(auditData(['tags' => ['billing', 'refund']]));
    $bare = app(DatabaseLedger::class)->write(auditData());

    expect($labelled->toArray()['tags'])->toBe(['billing', 'refund'])
        ->and($bare->toArray()['tags'])->toBe([]);
});

it('gathers what makes the entry provable under one key', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect($audit->toArray()['integrity'])->toBe([
        'stream' => $audit->stream,
        'sequence' => $audit->sequence,
        'algorithm' => 'sha256',
        'payload_version' => 1,
        'previous_hash' => null,
        'hash' => $audit->hash,
        'signature_key_id' => null,
        'verified' => null,
    ]);
});

it('keeps both clocks, to the microsecond', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'occurred_at' => new DateTimeImmutable('2026-08-26 10:02:03.456789'),
    ]));

    expect($audit->toArray()['occurred_at'])->toStartWith('2026-08-26T10:02:03.456789')
        ->and($audit->toArray())->toHaveKey('created_at');
});

it('says the severity and the source as the words they are', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect($audit->toArray()['severity'])->toBe('info')
        ->and($audit->toArray()['source'])->toBe('system');
});

it('survives a round trip through json', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'tags' => ['billing'],
        'changes' => [['path' => '/total', 'op' => 'replace', 'old' => 1, 'new' => 2]],
    ]));

    expect(json_decode($audit->toJson(), true))->toBe($audit->toArray());
});
