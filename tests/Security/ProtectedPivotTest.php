<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedTeam;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\keyring;
use function ElPandaPe\Sentinel\Tests\lineOf;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->team = ProtectedTeam::query()->create(['name' => 'Ops']);
        $this->member = Member::query()->create(['name' => 'Ada']);
    });
});

it('masks a pivot column the parent declares, before the ledger seals it', function (): void {
    $this->team->members()->attach($this->member->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    $pivot = lineOf(auditsOf($this->team)->sole())['pivot_after'];

    expect($pivot['role'])->not->toBe('lead')
        ->and($pivot['role'])->toBeString();
});

it('encrypts a pivot column the parent declares, and says which one it encrypted', function (): void {
    $this->team->members()->attach($this->member->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    $audit = auditsOf($this->team)->sole();
    $pivot = lineOf($audit)['pivot_after'];

    expect($audit->encryption['fields'])->toBe(['expires_at'])
        ->and($pivot['expires_at'])->not->toBe('2026-01-01')
        ->and(keyring()->for('default')->decrypt((string) $pivot['expires_at']))->toBe('2026-01-01');
});

it('protects both sides of a pivot that changed, not just the new one', function (): void {
    $this->team->members()->attach($this->member->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);
    $this->team->members()->updateExistingPivot($this->member->getKey(), ['expires_at' => '2027-01-01']);

    $line = lineOf(auditsOf($this->team)->last());

    expect(keyring()->for('default')->decrypt((string) $line['pivot_before']['expires_at']))->toBe('2026-01-01')
        ->and(keyring()->for('default')->decrypt((string) $line['pivot_after']['expires_at']))->toBe('2027-01-01')
        ->and($line['pivot_before']['role'])->not->toBe('lead');
});

it('hashes over the ciphertext, so the entry verifies where no key exists', function (): void {
    $this->team->members()->attach($this->member->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    expect(auditsOf($this->team)->sole()->verifyIntegrity())->toBeTrue();
});

it('projects the protected pivot, never the plaintext', function (): void {
    $this->team->members()->attach($this->member->getKey(), ['role' => 'lead', 'expires_at' => '2026-01-01']);

    $projected = auditsOf($this->team)->sole()->relations->sole();

    expect($projected->pivot_after['role'])->not->toBe('lead')
        ->and($projected->pivot_after['expires_at'])->not->toBe('2026-01-01');
});
