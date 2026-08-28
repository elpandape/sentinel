<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditPage;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\insertAudit;

it('opens the trail from the facade with nothing narrowed yet', function (): void {
    expect(Sentinel::audits())->toBeInstanceOf(AuditQuery::class)
        ->and(Sentinel::audits()->subject)->toBeNull();
});

it('reads back the entries a model wrote', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $found = Sentinel::audits()->for($subject)->get();

    expect($found)->toBeInstanceOf(AuditCollection::class)
        ->and($found->pluck('event')->all())->toBe(['created', 'updated']);
});

it('answers an unnarrowed query without reaching for the whole table', function (): void {
    AuditedSubject::query()->create(['name' => 'Ada']);

    expect(Sentinel::audits()->get())->toHaveCount(1);
});

it('refuses an unnarrowed read rather than answering with a prefix that looks whole', function (): void {
    Sentinel::withoutAuditing(function (): void {
        foreach (range(1, AuditQuery::DEFAULT_LIMIT + 10) as $sequence) {
            insertAudit(['sequence' => $sequence]);
        }
    });

    expect(fn (): AuditCollection => Sentinel::audits()->get())->toThrow(QueryException::class)
        ->and(Sentinel::audits()->take(AuditQuery::DEFAULT_LIMIT)->get())->toHaveCount(AuditQuery::DEFAULT_LIMIT);
});

it('hands back one page and says whether another follows', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->update(['name' => 'Hedy']);

    $page = Sentinel::audits()->for($subject)->paginate(2);

    expect($page)->toBeInstanceOf(AuditPage::class)
        ->and($page)->toHaveCount(2)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->page)->toBe(1)
        ->and($page->perPage)->toBe(2)
        ->and(collect($page)->pluck('event')->all())->toBe(['created', 'updated']);
});

it('walks to the last page, which says there is nothing behind it', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->update(['name' => 'Hedy']);

    $page = Sentinel::audits()->for($subject)->latest()->paginate(2, 2);

    expect($page->entries->pluck('event')->all())->toBe(['created'])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->page)->toBe(2);
});

it('hands back a page of one entry, which is still a page', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $page = Sentinel::audits()->for($subject)->paginate(1);

    expect($page)->toHaveCount(1)
        ->and($page->perPage)->toBe(1)
        ->and($page->hasMore)->toBeTrue();
});

it('says there is nothing behind a page that filled exactly to its last entry', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->update(['name' => 'Hedy']);
    $subject->update(['name' => 'Radia']);

    $page = Sentinel::audits()->for($subject)->paginate(2, 2);

    expect($page)->toHaveCount(2)
        ->and($page->hasMore)->toBeFalse();
});

it('asks the store for exactly one entry more than the page holds', function (): void {
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    Sentinel::audits()->paginate(25, 3);

    expect(end($statements))->toContain('limit 26')
        ->and(end($statements))->toContain('offset 50');
});

it('refuses a page that cannot exist', function (int $perPage, int $page): void {
    expect(fn (): AuditPage => Sentinel::audits()->paginate($perPage, $page))
        ->toThrow(QueryException::class, 'is not a page');
})->with([
    'no entries in it' => [0, 1],
    'numbered before the first' => [10, 0],
]);

it('narrows a whole trail down to what one query asked for', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    AuditedSubject::query()->create(['name' => 'Grace']);

    $found = Sentinel::audits()->for($subject)->whereSeverity(Severity::Info)->latest()->get();

    expect($found)->toHaveCount(1)
        ->and($found->firstOrFail()->subject_id)->toBe((string) $subject->getKey());
});
