<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Mass\MassCapture;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\Post;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\massEntries;
use function ElPandaPe\Sentinel\Tests\presenter;
use function ElPandaPe\Sentinel\Tests\seedSubjects;
use function ElPandaPe\Sentinel\Tests\statementsDuring;

it('records nothing at all for a query that did not ask', function (): void {
    seedSubjects(20);

    $statements = statementsDuring(static fn (): int => AuditedSubject::query()
        ->where('active', true)
        ->update(['status' => 'archived']));

    expect($statements)->toBe(1)
        ->and(Audit::query()->count())->toBe(0);
});

it('writes one entry for a set of any size when the mode is summary', function (): void {
    seedSubjects(500);

    $affected = AuditedSubject::query()->where('active', true)->auditing()->update(['status' => 'archived']);

    $entries = massEntries();

    expect($affected)->toBe(500)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->audit_type)->toBe(MassCapture::AUDIT_TYPE)
        ->and($entries[0]->event)->toBe('updated')
        ->and($entries[0]->affected_rows)->toBe(500)
        ->and($entries[0]->subject_type)->toBe(AuditedSubject::class)
        ->and($entries[0]->subject_id)->toBeNull();
});

it('never reads the set it is about to change, which is the whole of what summary buys', function (): void {
    seedSubjects(200);

    $reads = [];

    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'fixture_audited_subjects')) {
            $reads[] = $query->sql;
        }
    });

    AuditedSubject::query()->where('active', true)->auditing()->update(['status' => 'archived']);

    expect($reads)->toBeEmpty();
});

it('costs the statement, the entry and the tail of the chain, and nothing else', function (): void {
    seedSubjects(200);

    $statements = statementsDuring(static fn (): int => AuditedSubject::query()
        ->where('active', true)
        ->auditing()
        ->update(['status' => 'archived']));

    expect($statements)->toBe(3);
});

it('records the columns it wrote without an old side it never read', function (): void {
    seedSubjects(3);

    AuditedSubject::query()->auditing()->update(['status' => 'archived']);

    expect(massEntries()[0]->getAttribute('changes'))
        ->toBe([['path' => '/status', 'op' => 'replace', 'new' => 'archived']]);
});

it('records the criteria the operation was aimed at', function (): void {
    seedSubjects(3);

    AuditedSubject::query()->where('status', 'draft')->auditing()->update(['status' => 'archived']);

    expect(massEntries()[0]->criteria)->toBe(['wheres' => [
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'status', 'operator' => '=', 'value' => 'draft'],
    ]]);
});

it('writes no entry for an update that matched no row, and spends no sequence on it', function (): void {
    seedSubjects(3);

    $affected = AuditedSubject::query()->where('name', 'nobody')->auditing()->update(['status' => 'archived']);

    expect($affected)->toBe(0)->and(Audit::query()->count())->toBe(0);
});

it('describes every row when the mode is individual, and the set as well', function (): void {
    seedSubjects(4);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $entries = massEntries();

    expect($entries)->toHaveCount(5)
        ->and($entries[0]->affected_rows)->toBe(4)
        ->and($entries[0]->subject_id)->toBeNull()
        ->and(array_slice(array_map(static fn (Audit $entry): ?string => $entry->subject_id, $entries), 1))
        ->toBe(['1', '2', '3', '4']);
});

it('gives an individual entry the state the row was really in, and the one it moved to', function (): void {
    seedSubjects(1);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $row = massEntries()[1];

    expect($row->before['status'] ?? null)->toBe('draft')
        ->and($row->after['status'] ?? null)->toBe('archived')
        ->and($row->getAttribute('changes'))
        ->toBe([['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'archived']]);
});

it('leaves an individual entry carrying no criteria and no count of its own', function (): void {
    seedSubjects(1);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    expect(massEntries()[1]->criteria)->toBeNull()
        ->and(massEntries()[1]->affected_rows)->toBeNull();
});

it('correlates the summary and its rows under one operation', function (): void {
    seedSubjects(3);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $ids = array_unique(array_map(static fn (Audit $entry): ?string => $entry->transaction_id, massEntries()));

    expect($ids)->toHaveCount(1)->and($ids[0])->toBeString();
});

it('keeps the identifier of the business operation it was already inside', function (): void {
    seedSubjects(2);

    Sentinel::transaction('archive-everything', static function (): void {
        AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);
    });

    $ids = array_unique(array_map(static fn (Audit $entry): ?string => $entry->transaction_id, massEntries()));

    expect($ids)->toHaveCount(1)
        ->and(DB::table('sentinel_transactions')->count())->toBe(1)
        ->and(DB::table('sentinel_transactions')->value('name'))->toBe('archive-everything');
});

it('describes the rows one by one while the set stays under the threshold', function (): void {
    config()->set('sentinel.mass_operations.threshold', 5);
    seedSubjects(5);

    AuditedSubject::query()->auditing('hybrid')->update(['status' => 'archived']);

    expect(massEntries())->toHaveCount(6);
});

it('degrades to the summary alone the moment the set is one row over the threshold', function (): void {
    config()->set('sentinel.mass_operations.threshold', 5);
    seedSubjects(6);

    AuditedSubject::query()->auditing('hybrid')->update(['status' => 'archived']);

    $entries = massEntries();

    expect($entries)->toHaveCount(1)->and($entries[0]->affected_rows)->toBe(6);
});

it('never holds a set it is not going to describe, whatever its size', function (): void {
    config()->set('sentinel.mass_operations.threshold', 5);
    seedSubjects(200);

    $read = null;

    DB::listen(static function (QueryExecuted $query) use (&$read): void {
        if ($read === null && str_contains(strtolower($query->sql), 'select')) {
            $read = $query->sql;
        }
    });

    AuditedSubject::query()->auditing('hybrid')->update(['status' => 'archived']);

    expect($read)->toContain('limit 6');
});

it('captures the state of every row a delete is about to destroy', function (): void {
    seedSubjects(3);

    $deleted = AuditedSubject::query()->auditing('individual')->delete();

    $entries = massEntries();

    expect($deleted)->toBe(3)
        ->and($entries)->toHaveCount(4)
        ->and($entries[0]->event)->toBe('deleted')
        ->and($entries[1]->before['name'] ?? null)->toBe('subject 1')
        ->and($entries[1]->after)->toBeNull()
        ->and(AuditedSubject::query()->count())->toBe(0);
});

it('records an upsert as the rows it sent and what the engine reported', function (): void {
    AuditedSubject::query()->auditing()->upsert(
        [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']],
        ['id'],
        ['name'],
    );

    $entry = massEntries()[0];

    expect($entry->event)->toBe('upserted')
        ->and($entry->criteria)
        ->toBe(['columns' => ['id', 'name'], 'unique_by' => ['id'], 'update' => ['name'], 'rows' => 2])
        ->and($entry->affected_rows)->toBeInt();
});

it('keeps an upsert a summary whatever the mode asks for, having no criteria to read rows back by', function (): void {
    AuditedSubject::query()->auditing('individual')->upsert([['id' => 1, 'name' => 'Ada']], ['id'], ['name']);

    expect(massEntries())->toHaveCount(1)
        ->and(massEntries()[0]->subject_id)->toBeNull();
});

it('keeps the earlier state and claims no later one when a column is written from an expression', function (): void {
    seedSubjects(2, ['price' => '10']);

    AuditedSubject::query()->auditing('individual')->update([
        'status' => 'archived',
        'price' => DB::raw('price + 1'),
    ]);

    $entries = massEntries();

    expect($entries[0]->criteria['writes'] ?? null)->toBe(['price'])
        ->and($entries[1]->before['price'] ?? null)->toBe('10')
        ->and($entries[1]->after)->toBeNull()
        ->and($entries[1]->getAttribute('changes'))->toBeNull();
});

it('sends the whole batch to the queue and settles it there', function (): void {
    config()->set('sentinel.mode', 'queue');
    Bus::fake();
    seedSubjects(3);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    Bus::assertDispatchedTimes(SettleAudit::class, 4);
});

it('holds the whole batch in the buffer and settles it whole when it is vacated', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    seedSubjects(3);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    expect(app(Buffer::class)->size())->toBe(4)
        ->and(Audit::query()->count())->toBe(0);

    app(Flusher::class)->flush();

    expect(massEntries())->toHaveCount(4);
});

it('refuses a model that never said it could be audited', function (): void {
    expect(static fn (): int => Post::query()->auditing()->update(['id' => 1]))
        ->toThrow(ConfigurationException::class, 'does not use the Auditable trait');
});

it('refuses a mode nobody has heard of', function (): void {
    expect(static fn (): int => AuditedSubject::query()->auditing('thorough')->update(['status' => 'x']))
        ->toThrow(ConfigurationException::class, 'auditing');
});

it('redacts a binding the model declared, so the search does not leak what the snapshot hides', function (): void {
    DB::table('fixture_audited_subjects')->insert(['name' => 'Ada', 'email' => 'ada@example.com']);

    ProtectedSubject::query()->where('email', 'ada@example.com')->auditing()->update(['name' => 'Grace']);

    $written = json_encode(DB::table('sentinel_audits')->get()->all());

    expect($written)->toBeString()->not->toContain('ada@example.com');
});

it('leaves the statement untouched while the engine is paused', function (): void {
    seedSubjects(3);

    $affected = Sentinel::withoutAuditing(static fn (): int => AuditedSubject::query()
        ->auditing('individual')
        ->update(['status' => 'archived']));

    expect($affected)->toBe(3)->and(Audit::query()->count())->toBe(0);
});

it('serialises what it was aimed at and how much it reached', function (): void {
    seedSubjects(4);

    AuditedSubject::query()->where('status', 'draft')->auditing()->update(['status' => 'archived']);

    $serialised = massEntries()[0]->toArray();

    expect($serialised['affected_rows'])->toBe(4)
        ->and($serialised['criteria'])->toBe(['wheres' => [
            ['type' => 'basic', 'boolean' => 'and', 'column' => 'status', 'operator' => '=', 'value' => 'draft'],
        ]]);
});

it('reads a mass entry as the set it was about, not as one thing that happened to nothing', function (): void {
    seedSubjects(500);

    AuditedSubject::query()->auditing()->update(['status' => 'archived']);

    expect(presenter()->entry(massEntries()[0]))->toBe('Someone changed 500 AuditedSubject records');
});

it('reads a row of a mass operation as the record it was about', function (): void {
    seedSubjects(1);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    expect(presenter()->entry(massEntries()[1]))->toBe('Someone changed AuditedSubject #1');
});
