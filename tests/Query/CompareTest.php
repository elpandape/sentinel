<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\ComparisonException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Query\Comparison;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\versioned;

beforeEach(function (): void {
    $ledger = app(DatabaseLedger::class);

    foreach ([100, 150, 200, 250, 300, 350, 400] as $total) {
        $ledger->write(auditData(versioned($total)));
    }
});

it('compares two versions that are not next to each other', function (): void {
    $comparison = Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 7);

    expect($comparison->diff->toArray())->toBe([
        ['path' => '/total', 'op' => 'replace', 'old' => 100, 'new' => 400],
    ]);
});

it('hands back the two entries it compared, not only what changed', function (): void {
    $comparison = Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 4);

    expect($comparison->from->version)->toBe(1)
        ->and($comparison->to->version)->toBe(4)
        ->and($comparison)->toBeInstanceOf(Comparison::class);
});

it('compares in the direction it was asked to', function (): void {
    $forwards = Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 7)->diff->toArray();
    $backwards = Sentinel::audits()->for(AuditedSubject::class, 7)->compare(7, 1)->diff->toArray();

    expect($forwards[0]['old'])->toBe(100)
        ->and($backwards[0]['old'])->toBe(400);
});

it('says nothing changed between an entry and itself', function (): void {
    expect(Sentinel::audits()->for(AuditedSubject::class, 7)->compare(3, 3)->diff->isEmpty())->toBeTrue();
});

it('refuses to compare without knowing whose versions they are', function (): void {
    expect(fn (): Comparison => Sentinel::audits()->compare(1, 7))
        ->toThrow(ComparisonException::class, 'needs to know whose versions they are');
});

it('refuses a version this subject never reached', function (): void {
    expect(fn (): Comparison => Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 99))
        ->toThrow(ComparisonException::class, 'No entry of this subject carries version 99');
});

it('refuses to compare two entries about different subjects', function (): void {
    $mine = Sentinel::audits()->for(AuditedSubject::class, 7)->get()->firstOrFail();
    $theirs = app(DatabaseLedger::class)->write(auditData(['subject_type' => AuditedSubject::class, 'subject_id' => '8']));

    expect(fn (): Comparison => $mine->comparedTo($theirs))
        ->toThrow(ComparisonException::class, 'have no shared history to compare');
});

it('compares two entries handed to it directly', function (): void {
    $entries = Sentinel::audits()->for(AuditedSubject::class, 7)->get();

    expect($entries->firstOrFail()->comparedTo($entries->last())->diff->toArray())
        ->toBe([['path' => '/total', 'op' => 'replace', 'old' => 100, 'new' => 400]]);
});

it('resolves a repeated version number to the newest entry carrying it', function (): void {
    foreach ([[frozenUlid('TIE1'), 500], [frozenUlid('TIE2'), 600]] as [$id, $total]) {
        insertAudit([
            'id' => $id,
            'sequence' => 900 + $total,
            'subject_type' => AuditedSubject::class,
            'subject_id' => '7',
            'version' => 7,
            'after' => json_encode(['total' => $total, 'status' => 'open']),
            'created_at' => '2030-01-01 00:00:00.000000',
        ]);
    }

    $comparison = Sentinel::audits()->for(AuditedSubject::class, 7)->compare(1, 7);

    expect($comparison->to->id)->toBe(frozenUlid('TIE2'))
        ->and($comparison->to->after['total'] ?? null)->toBe(600);
});

it('narrows to the entries at the versions named', function (): void {
    expect(Sentinel::audits()->for(AuditedSubject::class, 7)->whereVersion(2, 5)->get()->pluck('version')->all())
        ->toBe([2, 5]);
});

it('reads a repeated call as one question with both versions', function (): void {
    expect(Sentinel::audits()->for(AuditedSubject::class, 7)->whereVersion(2)->whereVersion(5)->get()->pluck('version')->all())
        ->toBe([2, 5]);
});

it('carries every version asked for across calls, once each, as a list', function (): void {
    expect(Sentinel::audits()->whereVersion(2, 5, 2)->whereVersion(5, 7)->versions)
        ->toBe([2, 5, 7]);
});

it('names whereVersion as the method that reaches the version filter', function (): void {
    expect(Filter::Version->method())->toBe('whereVersion')
        ->and(Filter::assumed())->not->toContain(Filter::Version);
});
