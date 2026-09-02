<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\redactor;

it('calls an untouched entry sealed', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    expect($written->verifyContent())->toBe(ContentState::Sealed)
        ->and($written->verifyIntegrity())->toBeTrue();
});

it('calls a tombstone redacted, and never intact nor tampered', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    redactor()->redact($written, 'erasure request');

    $reloaded = Audit::query()->findOrFail($written->id);

    expect($reloaded->verifyContent())->toBe(ContentState::Redacted)
        ->and($reloaded->verifyIntegrity())->toBeFalse();
});

it('calls a row somebody emptied by hand altered', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    DB::table(auditsTable())->where('id', $written->id)->update(['before' => null]);

    expect(Audit::query()->findOrFail($written->id)->verifyContent())->toBe(ContentState::Altered);
});

it('calls a declared redaction with no second hash altered, because the word is not the proof', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    DB::table(auditsTable())->where('id', $written->id)->update([
        'before' => null,
        'redacted_at' => CarbonImmutable::now(),
        'redaction_reason' => 'looks official',
    ]);

    expect(Audit::query()->findOrFail($written->id)->verifyContent())->toBe(ContentState::Altered);
});

it('calls a tombstone somebody wrote into afterwards altered', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    redactor()->redact($written, 'erasure request');

    DB::table(auditsTable())->where('id', $written->id)->update(['after' => json_encode(['planted' => true])]);

    expect(Audit::query()->findOrFail($written->id)->verifyContent())->toBe(ContentState::Altered);
});

it('leaves the reason of a redaction outside what the second hash covers', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    redactor()->redact($written, 'erasure request');

    DB::table(auditsTable())->where('id', $written->id)->update(['redaction_reason' => 'rewritten later']);

    expect(Audit::query()->findOrFail($written->id)->verifyContent())->toBe(ContentState::Redacted);
});

it('calls a tombstone over an already empty entry redacted, not sealed', function (): void {
    $written = ledger()->write(auditData());

    expect($written->before)->toBeNull();

    redactor()->redact($written, 'erasure request');

    $reloaded = Audit::query()->findOrFail($written->id);

    expect($reloaded->redacted_hash)->toBe($reloaded->hash)
        ->and($reloaded->verifyContent())->toBe(ContentState::Redacted);
});
