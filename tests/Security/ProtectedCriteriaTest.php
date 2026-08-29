<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData;
use ElPandaPe\Sentinel\Tests\Fixtures\BlanketMasker;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\encryptedEntry;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\protectedEntry;
use function ElPandaPe\Sentinel\Tests\searchedFor;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([MaskSensitiveData::class]);
    config()->set('sentinel.security.redaction.masker', BlanketMasker::class);
});

it('masks what a mass operation went looking for, not only what it found', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'email', 'operator' => '=', 'value' => 'ada@example.com'],
    ]))));

    expect($audit?->criteria)->toBe(['wheres' => [
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'email', 'operator' => '=', 'value' => BlanketMasker::MASK],
    ]]);
});

it('masks every value of a set, because one of them is enough', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([
        ['type' => 'in', 'boolean' => 'and', 'column' => 'email', 'count' => 2, 'values' => ['ada@example.com', 'grace@example.com']],
    ]))));

    expect($audit?->criteria['wheres'][0]['values'] ?? null)
        ->toBe([BlanketMasker::MASK, BlanketMasker::MASK]);
});

it('descends into a group, which is where a clause hides from a walk of the top level', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([
        ['type' => 'nested', 'boolean' => 'and', 'wheres' => [
            ['type' => 'basic', 'boolean' => 'and', 'column' => 'email', 'operator' => '=', 'value' => 'ada@example.com'],
        ]],
    ]))));

    expect($audit?->criteria['wheres'][0]['wheres'][0]['value'] ?? null)->toBe(BlanketMasker::MASK);
});

it('leaves a clause about a column nobody declared exactly as it arrived', function (): void {
    $clause = ['type' => 'basic', 'boolean' => 'and', 'column' => 'name', 'operator' => '=', 'value' => 'Ada'];

    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([$clause]))));

    expect($audit?->criteria)->toBe(['wheres' => [$clause]]);
});

it('leaves an opaque clause alone, having nothing of the caller to protect', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([['type' => 'raw', 'boolean' => 'and']]))));

    expect($audit?->criteria)->toBe(['wheres' => [['type' => 'raw', 'boolean' => 'and']]]);
});

it('leaves the query own vocabulary alone: the tables of a join and the columns of an upsert', function (): void {
    $criteria = [
        'wheres' => [['type' => 'basic', 'boolean' => 'and', 'column' => 'email', 'operator' => '=', 'value' => 'ada@example.com']],
        'joins' => [['type' => 'inner', 'table' => 'email']],
    ];

    $audit = pipeline()->process(auditData(protectedEntry(['criteria' => $criteria])));

    expect($audit?->criteria['joins'] ?? null)->toBe([['type' => 'inner', 'table' => 'email']]);
});

it('passes an upsert through whole, because it names rows rather than searching for them', function (): void {
    $criteria = ['columns' => ['email'], 'unique_by' => ['email'], 'update' => ['email'], 'rows' => 3];

    $audit = pipeline()->process(auditData(protectedEntry(['criteria' => $criteria])));

    expect($audit?->criteria)->toBe($criteria);
});

it('leaves an entry with no criteria without inventing one', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(['after' => ['email' => 'ada@example.com']])));

    expect($audit?->criteria)->toBeNull();
});

it('hashes the value a criteria compared against, so the search stays comparable', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(searchedFor([
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'secret', 'operator' => '=', 'value' => 'launch codes'],
    ]))));

    expect($audit?->criteria['wheres'][0]['value'] ?? null)
        ->toBeString()
        ->not->toBe('launch codes')
        ->toHaveLength(64);
});

it('counts a field found only in the criteria as found, and names it in the encryption block', function (): void {
    stagedPipeline([EncryptSensitiveData::class]);

    $audit = pipeline()->process(auditData(encryptedEntry(searchedFor([
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'secret', 'operator' => '=', 'value' => 'launch codes'],
    ]))));

    expect($audit?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default'])
        ->and($audit?->criteria['wheres'][0]['value'] ?? null)->toBeString()->not->toBe('launch codes');
});
