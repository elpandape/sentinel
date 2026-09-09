<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;

use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;

beforeEach(function (): void {
    insertAudit(['id' => frozenUlid('AAA2'), 'sequence' => 1, 'event' => 'minted second', 'created_at' => '2026-08-26 10:00:00.000100']);
    insertAudit(['id' => frozenUlid('AAA1'), 'sequence' => 2, 'event' => 'minted first', 'created_at' => '2026-08-26 10:00:00.000200']);
});

it('reads by the ledger clock by default, where the identifier does not agree with it', function (): void {
    expect(Sentinel::audits()->get()->pluck('event')->all())->toBe(['minted second', 'minted first']);
});

it('walks by the identifier behind a cursor, and misses nothing the clock would have hidden', function (): void {
    expect(Sentinel::audits()->after(frozenUlid('AAA0'))->get()->pluck('event')->all())->toBe(['minted first', 'minted second'])
        ->and(Sentinel::audits()->after(frozenUlid('AAA1'))->get()->pluck('event')->all())->toBe(['minted second'])
        ->and(Sentinel::audits()->after(frozenUlid('AAA2'))->get())->toBeEmpty();
});

it('resumes a walk page by page without a gap between the pages', function (): void {
    $first = Sentinel::audits()->after(frozenUlid('AAA0'))->take(1)->get();
    $second = Sentinel::audits()->after($first->firstOrFail()->id)->take(1)->get();

    expect([...$first->pluck('event')->all(), ...$second->pluck('event')->all()])->toBe(['minted first', 'minted second']);
});
