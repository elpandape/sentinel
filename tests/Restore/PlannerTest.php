<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\planner;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\reread;
use function ElPandaPe\Sentinel\Tests\restorableEntry;

it('plans the fields whose value would actually move', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace', 'status' => 'open']);
    $entry = restorableEntry($record, ['name' => 'Ada', 'status' => 'open']);

    $plan = planner()->for($entry, $record);

    expect($plan->applying)->toBe(['name' => 'Ada'])
        ->and($plan->skipped)->toBe(['status' => Omission::Unchanged])
        ->and($plan->keys())->toBe(['name']);
});

it('plans only the fields the caller asked for', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace', 'email' => 'grace@example.com']);
    $entry = restorableEntry($record, ['name' => 'Ada', 'email' => 'ada@example.com']);

    expect(planner()->for($entry, $record, ['email'])->applying)->toBe(['email' => 'ada@example.com']);
});

it('refuses everything when the record it is about is gone', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(planner()->for(restorableEntry($record, ['name' => 'Ada']), null)->refused)
        ->toBe(Omission::SubjectMissing);
});

it('refuses everything when the entry was redacted', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace']);
    $entry = restorableEntry($record, ['name' => 'Ada']);

    redactor()->redact($entry, 'erasure request');

    expect(planner()->for(reread($entry), $record)->refused)->toBe(Omission::EntryRedacted);
});

it('refuses everything when the entry no longer reproduces its own hash', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace']);
    $entry = restorableEntry($record, ['name' => 'Ada']);

    DB::table(auditsTable())->where('id', $entry->id)->update(['before' => '{"name":"Eve"}']);

    expect(planner()->for(reread($entry), $record)->refused)->toBe(Omission::EntryTampered);
});

it('refuses everything when the entry holds no earlier state', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(planner()->for(restorableEntry($record, [], ['before' => null]), $record)->refused)
        ->toBe(Omission::EntryStateless)
        ->and(planner()->for(restorableEntry($record, []), $record)->refused)
        ->toBe(Omission::EntryStateless);
});

it('never puts back the key that identifies the record', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $entry = restorableEntry($record, ['id' => 99, 'name' => 'Grace']);

    $plan = planner()->for($entry, $record);

    expect($plan->applying)->toBe(['name' => 'Grace'])
        ->and($plan->skipped)->toBe(['id' => Omission::IdentityField]);
});

it('skips a field the record no longer has and applies the rest', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace']);
    $entry = restorableEntry($record, ['name' => 'Ada', 'nickname' => 'the countess']);

    $plan = planner()->for($entry, $record);

    expect($plan->applying)->toBe(['name' => 'Ada'])
        ->and($plan->skipped)->toBe(['nickname' => Omission::UnknownField]);
});

it('skips a field the entry does not record', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace', 'status' => 'open']);
    $entry = restorableEntry($record, ['name' => 'Ada']);

    expect(planner()->for($entry, $record, ['status'])->skipped)
        ->toBe(['status' => Omission::UnrecordedField]);
});

it('never writes back a masked value or a digest', function (): void {
    $record = ProtectedSubject::query()->create(['email' => 'grace@example.com', 'secret' => 'now']);
    $entry = restorableEntry($record, ['email' => 'a****a@e****e.c****m', 'secret' => str_repeat('f', 64)]);

    $plan = planner()->for($entry, $record);

    expect($plan->applying)->toBeEmpty()
        ->and($plan->skipped)->toBe([
            'email' => Omission::RedactedField,
            'secret' => Omission::HashedField,
        ]);
});

it('decrypts a field the entry stored under a key that is still on the keyring', function (): void {
    $record = EncryptedSubject::query()->create(['secret' => 'now']);
    $opening = $record->audits()->firstOrFail();
    $record->update(['secret' => 'later']);

    expect(planner()->for($opening, $record)->applying)->toBe(['secret' => 'now']);
});

it('skips a field whose key left the keyring rather than writing back the ciphertext', function (): void {
    $record = EncryptedSubject::query()->create(['secret' => 'now']);
    $entry = restorableEntry($record, ['secret' => 'gAAAAA-not-a-payload'], [
        'encryption' => ['fields' => ['secret'], 'key_id' => 'retired'],
    ]);

    expect(planner()->for($entry, $record)->skipped)->toBe(['secret' => Omission::KeyUnavailable]);
});

it('puts back a value the entry stored before the model declared it encrypted', function (): void {
    $record = EncryptedSubject::query()->create(['secret' => 'now']);

    expect(planner()->for(restorableEntry($record, ['secret' => 'before']), $record)->applying)
        ->toBe(['secret' => 'before']);
});

it('refuses a whole restoration of an imported entry, which may not portray the whole record', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace', 'status' => 'open']);
    $entry = restorableEntry($record, ['name' => 'Ada', 'status' => 'closed'], ['source' => Source::Import]);

    expect(planner()->for($entry, $record)->refused)->toBe(Omission::EntryImported);
});

it('plans the fields a caller names on an imported entry, because there they know what they asked for', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace', 'status' => 'open']);
    $entry = restorableEntry($record, ['name' => 'Ada', 'status' => 'closed'], ['source' => Source::Import]);

    $plan = planner()->for($entry, $record, ['name']);

    expect($plan->refused)->toBeNull()
        ->and($plan->applying)->toBe(['name' => 'Ada']);
});

it('calls a redacted imported entry redacted, because that is the bigger fact about it', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Grace']);
    $entry = restorableEntry($record, ['name' => 'Ada'], ['source' => Source::Import]);

    redactor()->redact($entry, 'erasure request');

    expect(planner()->for(reread($entry), $record)->refused)->toBe(Omission::EntryRedacted);
});

it('says why in the language the application is using', function (): void {
    app()->setLocale('es');

    expect(Omission::EntryImported->message())->toContain('se importó de otro paquete');
});
