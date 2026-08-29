<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Restore\RestoreResult;

/**
 * What a restoration answers is public from v0.15.0, under the same rule as a serialised entry:
 * a reason may be added, none may be removed or renamed. It is frozen here and not inside
 * toArray(), because it is the answer to a call and not part of the entry.
 */
it('answers with every reason it declared and no others', function (): void {
    expect(array_map(static fn (Omission $reason): string => $reason->value, Omission::cases()))->toBe([
        'subject_missing',
        'entry_redacted',
        'entry_tampered',
        'entry_stateless',
        'cancelled',
        'unknown_field',
        'unrecorded_field',
        'identity_field',
        'redacted_field',
        'hashed_field',
        'key_unavailable',
        'related_missing',
        'unchanged',
    ]);
});

it('reads every reason out loud in both languages', function (string $locale): void {
    app()->setLocale($locale);

    foreach (Omission::cases() as $reason) {
        expect($reason->message('email'))->not->toBe('sentinel::sentinel.restore.'.$reason->value)
            ->and($reason->message('email'))->not->toBeEmpty();
    }
})->with(['en', 'es']);

it('carries the four things a restoration has to say', function (): void {
    $result = RestoreResult::of(['name'], ['email' => Omission::RedactedField]);

    expect($result->applied)->toBe(['name'])
        ->and($result->skipped)->toBe(['email' => Omission::RedactedField])
        ->and($result->refused)->toBeNull()
        ->and($result->entry)->toBeNull()
        ->and($result->reason('email'))->toBe(Omission::RedactedField)
        ->and($result->reason('name'))->toBeNull();
});

it('answers for every key at once when the whole restoration was refused', function (): void {
    $result = RestoreResult::refused(Omission::EntryTampered);

    expect($result->applied)->toBeEmpty()
        ->and($result->skipped)->toBeEmpty()
        ->and($result->refused)->toBe(Omission::EntryTampered)
        ->and($result->reason('anything at all'))->toBe(Omission::EntryTampered);
});

it('never answers a restoration with a boolean', function (): void {
    $returns = array_map(
        static fn (ReflectionMethod $method): ?string => $method->getReturnType() instanceof ReflectionNamedType
            ? $method->getReturnType()->getName()
            : null,
        new ReflectionClass(RestoreResult::class)->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($returns)->not->toContain('bool');
});
