<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Restore\RestoreResult;

it('separates what it put back from what it left alone', function (): void {
    $result = RestoreResult::of(['name', 'status'], ['email' => Omission::RedactedField]);

    expect($result->applied)->toBe(['name', 'status'])
        ->and($result->skipped)->toBe(['email' => Omission::RedactedField])
        ->and($result->refused)->toBeNull()
        ->and($result->entry)->toBeNull();
});

it('answers why a key was left alone, and nothing for one that was not', function (): void {
    $result = RestoreResult::of(['name'], ['secret' => Omission::HashedField]);

    expect($result->reason('secret'))->toBe(Omission::HashedField)
        ->and($result->reason('name'))->toBeNull();
});

it('answers the same refusal for every key when the entry itself was refused', function (): void {
    $result = RestoreResult::refused(Omission::EntryTampered);

    expect($result->refused)->toBe(Omission::EntryTampered)
        ->and($result->applied)->toBeEmpty()
        ->and($result->skipped)->toBeEmpty()
        ->and($result->reason('anything'))->toBe(Omission::EntryTampered);
});

it('reads a refusal in the language the application is running in', function (): void {
    expect(Omission::EntryRedacted->message())
        ->toBe('This entry has been redacted: its contents were destroyed on purpose and cannot put anything back.');

    app()->setLocale('es');

    expect(Omission::EntryRedacted->message())
        ->toBe('Este asiento está redactado: su contenido se destruyó a propósito y no puede devolver nada.');
});

it('names the key in the reasons that are about one', function (): void {
    expect(Omission::UnknownField->message('nickname'))->toBe('The record no longer has a nickname.')
        ->and(Omission::Unchanged->message('status'))->toBe('The status already holds the value this entry would put back.')
        ->and(Omission::Cancelled->message('status'))->toBe('A listener cancelled the restoration.');
});
