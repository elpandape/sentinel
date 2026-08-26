<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\hasher;

beforeEach(function (): void {
    $this->ledger = app(DatabaseLedger::class);
});

it('consumes consecutive sequences for the whole batch', function (): void {
    $written = $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect($written)->toBeInstanceOf(AuditCollection::class)
        ->and($written->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('chains the batch entry by entry', function (): void {
    [$first, $second, $third] = $this->ledger->writeMany([auditData(), auditData(), auditData()])->all();

    expect($first->previous_hash)->toBeNull()
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($third->previous_hash)->toBe($second->hash)
        ->and($third->hash)->toBe(hasher()->hash($third));
});

it('reads the tail of a stream once for the whole batch', function (): void {
    $tailReads = 0;
    DB::listen(function ($query) use (&$tailReads): void {
        if (str_contains($query->sql, 'order by') && str_contains($query->sql, 'desc')) {
            $tailReads++;
        }
    });

    $this->ledger->writeMany([auditData(), auditData(), auditData(), auditData()]);

    expect($tailReads)->toBe(1);
});

it('reads one tail per stream when the batch mixes them', function (): void {
    $written = $this->ledger->writeMany([
        auditData(['stream' => 'alpha']),
        auditData(['stream' => 'beta']),
        auditData(['stream' => 'alpha']),
    ]);

    expect($written->pluck('stream')->all())->toBe(['alpha', 'beta', 'alpha'])
        ->and($written->pluck('sequence')->all())->toBe([1, 1, 2])
        ->and($written[2]->previous_hash)->toBe($written[0]->hash);
});

it('continues the chain a previous batch left behind', function (): void {
    $first = $this->ledger->writeMany([auditData(), auditData()]);
    $second = $this->ledger->writeMany([auditData()]);

    expect($second[0]->sequence)->toBe(3)
        ->and($second[0]->previous_hash)->toBe($first[1]->hash);
});

it('counts versions inside the batch, not only against what is stored', function (): void {
    $subject = ['subject_type' => 'fixture', 'subject_id' => '1'];

    expect($this->ledger->writeMany([auditData($subject), auditData($subject)])->pluck('version')->all())
        ->toBe([1, 2]);
});

it('writes nothing for an empty batch', function (): void {
    expect($this->ledger->writeMany([])->all())->toBeEmpty()
        ->and(Audit::query()->count())->toBe(0);
});

it('persists every entry of the batch', function (): void {
    $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect(Audit::query()->count())->toBe(3);
});
