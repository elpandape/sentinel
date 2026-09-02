<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Exceptions\RedactionException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('empties every content column of the canonical payload, not only three', function (): void {
    $written = ledger()->write(auditData([
        'context' => ['ip' => '10.0.0.1', 'url' => 'https://example.test/orders/9'],
        'before' => ['city' => 'Lima'],
        'after' => ['city' => 'Arequipa'],
        'changes' => [['path' => '/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']],
        'metadata' => ['reason' => 'moved'],
        'criteria' => ['wheres' => [['column' => 'city', 'value' => 'Lima']]],
    ]));

    redactor()->redact($written, 'erasure request');

    $row = DB::table(auditsTable())->where('id', $written->id)->first();

    expect($row?->before)->toBeNull()
        ->and($row?->after)->toBeNull()
        ->and($row?->changes)->toBeNull()
        ->and($row?->metadata)->toBeNull()
        ->and($row?->criteria)->toBeNull()
        ->and(json_decode((string) $row?->context, true))->toBe([]);
});

it('names the six columns it empties rather than leaving the list to be guessed', function (): void {
    expect(Redactor::CONTENT)->toBe(['context', 'before', 'after', 'changes', 'metadata', 'criteria']);
});

it('keeps the sequence, the hash and the link of the entry it redacts', function (): void {
    $first = ledger()->write(auditData(['before' => ['a' => 1]]));
    $second = ledger()->write(auditData());

    redactor()->redact($first, 'erasure request');

    $reloaded = Audit::query()->findOrFail($first->id);

    expect($reloaded->sequence)->toBe($first->sequence)
        ->and($reloaded->hash)->toBe($first->hash)
        ->and($reloaded->previous_hash)->toBe($first->previous_hash)
        ->and(Audit::query()->findOrFail($second->id)->previous_hash)->toBe($first->hash);
});

it('seals a second hash over what it leaves, which the entry reproduces', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1], 'metadata' => ['b' => 2]]));

    $tombstone = redactor()->redact($written, 'erasure request');
    $reloaded = Audit::query()->findOrFail($written->id);

    expect($reloaded->redacted_hash)->toBe($tombstone->redactedHash)
        ->and($reloaded->redacted_hash)->toBe(hasher()->hash($reloaded))
        ->and($reloaded->redacted_hash)->not->toBe($reloaded->hash)
        ->and($reloaded->redaction_reason)->toBe('erasure request')
        ->and($reloaded->redacted_at)->not->toBeNull();
});

it('forgets the labels and the relation lines, which are content living in their own tables', function (): void {
    $written = ledger()->write(auditData([
        'tags' => ['gdpr', 'billing'],
        'changes' => [['relation' => 'members', 'operation' => 'attached', 'related_type' => 'member', 'related_id' => '9']],
    ]));

    expect(DB::table(auditTagsTable())->where('audit_id', $written->id)->count())->toBe(2)
        ->and(DB::table(auditRelationsTable())->where('audit_id', $written->id)->count())->toBe(1);

    redactor()->redact($written, 'erasure request');

    expect(DB::table(auditTagsTable())->where('audit_id', $written->id)->count())->toBe(0)
        ->and(DB::table(auditRelationsTable())->where('audit_id', $written->id)->count())->toBe(0);
});

it('gives the tombstone back a second time without writing a second trail', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $first = redactor()->redact($written, 'erasure request');
    $trails = Audit::query()->whereNotNull('source_audit_id')->count();

    $again = redactor()->redact(Audit::query()->findOrFail($written->id), 'erasure request');

    expect($again->auditId)->toBe($first->auditId)
        ->and($again->redactedHash)->toBe($first->redactedHash)
        ->and(Audit::query()->whereNotNull('source_audit_id')->count())->toBe($trails);
});

it('refuses an entry that no longer reproduces its own hash, so an alteration keeps showing', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    DB::table(auditsTable())->where('id', $written->id)->update(['before' => json_encode(['a' => 2])]);

    expect(fn (): mixed => redactor()->redact(Audit::query()->findOrFail($written->id), 'erasure request'))
        ->toThrow(RedactionException::class, 'does not reproduce its own hash');
});

it('refuses an entry whose range already left the hot table, naming the batch that holds it', function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);

    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    $archived = Audit::query()->where('sequence', 2)->firstOrFail();

    anchor('global', 4);
    pruner()->prune(
        frontiers(['model' => '1 day'])->of('global', new Carbon\CarbonImmutable('2026-09-30 12:00:00')),
        PruneAction::Archive,
        false,
    );

    expect(fn (): mixed => redactor()->redact($archived, 'erasure request'))
        ->toThrow(RedactionException::class, 'lives in the archive batch at');
});

it('refuses an entry whose range was retired with nothing kept, and says so differently', function (): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    $deleted = Audit::query()->where('sequence', 2)->firstOrFail();

    anchor('global', 4);
    pruner()->prune(
        frontiers(['model' => '1 day'])->of('global', new Carbon\CarbonImmutable('2026-09-30 12:00:00')),
        PruneAction::Delete,
        false,
    );

    expect(fn (): mixed => redactor()->redact($deleted, 'erasure request'))
        ->toThrow(RedactionException::class, 'left the hot table');
});

it('leaves a trail naming the actor, the reason and the entry it redacted', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $tombstone = redactor()->redact($written, 'GDPR erasure 4711', new Reference('member', '77'));

    $trail = $tombstone->trail;

    expect($trail)->not->toBeNull()
        ->and($trail?->source_audit_id)->toBe($written->id)
        ->and($trail?->event)->toBe('redacted')
        ->and($trail?->actor_type)->toBe('member')
        ->and($trail?->actor_id)->toBe('77')
        ->and($trail?->metadata['redaction']['reason'] ?? null)->toBe('GDPR erasure 4711')
        ->and($trail?->metadata['redaction']['sequence'] ?? null)->toBe($written->sequence);
});
