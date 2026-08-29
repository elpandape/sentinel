<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Http\Resources\AuditResource;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Http\Request;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\seedTheFrozenTrail;

/**
 * The shape of a serialised entry is public from v0.15.0 and can only grow. A key appearing here
 * is a decision to carry it to v1.0.0; a key disappearing is a break. Either one has to be made on
 * purpose, which is what this test is for.
 */
const FROZEN_KEYS = [
    'id',
    'audit_type',
    'event',
    'severity',
    'source',
    'subject',
    'actor',
    'impersonator',
    'tenant_id',
    'version',
    'changes',
    'before',
    'after',
    'metadata',
    'tags',
    'context',
    'transaction_id',
    'request_id',
    'trace_id',
    'span_id',
    'source_audit_id',
    'criteria',
    'affected_rows',
    'integrity',
    'occurred_at',
    'created_at',
];

const FROZEN_INTEGRITY_KEYS = [
    'stream',
    'sequence',
    'algorithm',
    'payload_version',
    'previous_hash',
    'hash',
    'signature_key_id',
    'verified',
];

it('publishes exactly the keys it froze', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect(array_keys($audit->toArray()))->toBe(FROZEN_KEYS)
        ->and(array_keys($audit->toArray()['integrity']))->toBe(FROZEN_INTEGRITY_KEYS);
});

it('publishes them for an entry that has nothing to say in any of them', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect(array_keys($audit->toArray()))->toBe(FROZEN_KEYS);
});

it('publishes them for every entry payload version one wrote', function (): void {
    seedTheFrozenTrail();

    Sentinel::audits()->get()->each(function (Audit $audit): void {
        expect(array_keys($audit->toArray()))->toBe(FROZEN_KEYS)
            ->and(array_keys($audit->toArray()['integrity']))->toBe(FROZEN_INTEGRITY_KEYS)
            ->and($audit->toJson())->toBeString();
    });
});

it('keeps the ciphertext and the signature where they are', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'encryption' => ['fields' => ['secret'], 'key_id' => 'default'],
    ]));

    expect($audit->toArray())->not->toHaveKey('encryption')
        ->not->toHaveKey('signature')
        ->not->toHaveKey('capture_id')
        ->not->toHaveKey('redacted_at')
        ->not->toHaveKey('relation');
});

it('publishes the two keys v0.17.0 owns, and null for an entry that is not a mass operation', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect($audit->toArray()['criteria'])->toBeNull()
        ->and($audit->toArray()['affected_rows'])->toBeNull();
});

it('says an entry was not checked rather than that it failed', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());

    expect($audit->toArray()['integrity']['verified'])->toBeNull()
        ->and($audit->verifyIntegrity())->toBeTrue();
});

it('names the entry a restoration came from', function (): void {
    $source = app(DatabaseLedger::class)->write(auditData());
    $restore = app(DatabaseLedger::class)->write(auditData(['source_audit_id' => $source->id]));

    expect($restore->toArray()['source_audit_id'])->toBe($source->id)
        ->and($source->toArray()['source_audit_id'])->toBeNull();
});

it('hands the resource the entry and lets it add nothing', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData(['tags' => ['billing']]));

    expect(new AuditResource($audit)->toArray(Request::create('/')))->toBe($audit->toArray());
});

it('wraps a page of them without changing any of them', function (): void {
    app(DatabaseLedger::class)->write(auditData());
    app(DatabaseLedger::class)->write(auditData());

    $entries = Sentinel::audits()->get();
    $wrapped = AuditResource::collection($entries)->toArray(Request::create('/'));

    expect($wrapped)->toHaveCount(2)
        ->and($wrapped[0])->toBe($entries[0]->toArray())
        ->and($wrapped[1])->toBe($entries[1]->toArray());
});
