<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\EncryptedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\OwenItTrail;
use Illuminate\Console\Command;

use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\importing;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\seedForeignTrail;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());
});

it('verifies the chain it built, from the point of import to the end of it', function (): void {
    importing();

    expect(verifier()->verifyEverything()->isIntact())->toBeTrue();

    $this->artisan('sentinel:verify')->assertExitCode(Command::SUCCESS);
});

it('leaves every imported entry reproducing its own hash', function (): void {
    importing();

    Audit::query()->get()->each(function (Audit $entry): void {
        expect($entry->verifyIntegrity())->toBeTrue();
    });
});

it('links what it imported onto the chain that was already there', function (): void {
    seedChain(3);

    importing();

    $entries = Audit::query()->orderBy('sequence')->get();

    expect($entries)->toHaveCount(6)
        ->and($entries[3]->previous_hash)->toBe($entries[2]->hash)
        ->and(verifier()->verifyEverything()->isIntact())->toBeTrue();
});

it('keeps the entries written before it verifying exactly as they did', function (): void {
    seedChain(3);

    $before = Audit::query()->orderBy('sequence')->pluck('hash')->all();

    importing();

    expect(Audit::query()->orderBy('sequence')->limit(3)->pluck('hash')->all())->toBe($before);
});

it('orders what it wrote by the ledger clock and not by when the facts happened', function (): void {
    seedChain(1);

    importing();

    $entries = Audit::query()->orderBy('sequence')->get();

    expect($entries->last()->occurred_at->year)->toBe(2024)
        ->and($entries->first()->occurred_at->year)->toBeGreaterThan(2024);
});

it('encrypts a field the model protects, however plainly the source held it', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, [[
        'id' => 1,
        'user_type' => null,
        'user_id' => null,
        'event' => 'created',
        'auditable_type' => EncryptedSubject::class,
        'auditable_id' => 77,
        'old_values' => '[]',
        'new_values' => '{"name":"Ada","secret":"in the clear over there"}',
        'url' => null,
        'ip_address' => null,
        'user_agent' => null,
        'tags' => null,
        'created_at' => '2024-01-02 03:04:05',
        'updated_at' => '2024-01-02 03:04:05',
    ]]);

    importing();

    $entry = Audit::query()->firstOrFail();

    expect($entry->after['secret'] ?? null)->not->toBe('in the clear over there')
        ->and($entry->encryption)->not->toBeNull()
        ->and(json_encode($entry->getAttributes()))->not->toContain('in the clear over there');
});

it('leaves the frozen entries of payload version one reproducing their hashes', function (): void {
    importing();

    foreach (GoldenLedger::entries() as [$attributes, $canonical, $hash]) {
        $audit = new Audit()->forceFill($attributes);

        expect(new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($audit)))->toBe($canonical)
            ->and(hasher()->hash($audit))->toBe($hash);
    }
});

it('writes what it imports under the payload version everything else uses', function (): void {
    importing();

    expect(Audit::query()->pluck('payload_version')->unique()->all())->toBe([1]);
});
