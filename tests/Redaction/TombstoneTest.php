<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Tombstone;

use function ElPandaPe\Sentinel\Tests\auditRow;

it('keeps the moment and the reason an already redacted entry carries', function (): void {
    $audit = new Audit()->forceFill(auditRow([
        'redacted_at' => '2026-08-20 09:00:00.000000',
        'redaction_reason' => 'subject access request 41',
        'redacted_hash' => str_repeat('b', 64),
    ]));

    $tombstone = Tombstone::of($audit);

    expect($tombstone->redactedAt->format('Y-m-d H:i:s'))->toBe('2026-08-20 09:00:00')
        ->and($tombstone->reason)->toBe('subject access request 41')
        ->and($tombstone->redactedHash)->toBe(str_repeat('b', 64));
});

it('falls back to a blank reason and a blank hash when the entry carries neither', function (): void {
    $audit = new Audit()->forceFill(auditRow(['redaction_reason' => null, 'redacted_hash' => null]));

    $tombstone = Tombstone::of($audit);

    expect($tombstone->reason)->toBeEmpty()
        ->and($tombstone->redactedHash)->toBeEmpty();
});
