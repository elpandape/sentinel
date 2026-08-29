<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\massEntries;
use function ElPandaPe\Sentinel\Tests\seedSubjects;
use function ElPandaPe\Sentinel\Tests\seedTheFrozenTrail;
use function ElPandaPe\Sentinel\Tests\verifier;
use function ElPandaPe\Sentinel\Tests\withSortedKeys;

it('counts a set of three thousand five hundred rows without reading one of them', function (): void {
    seedSubjects(3500);

    $affected = AuditedSubject::query()->where('active', true)->auditing()->update(['status' => 'archived']);

    expect($affected)->toBe(3500)
        ->and(massEntries())->toHaveCount(1)
        ->and(massEntries()[0]->affected_rows)->toBe(3500);
});

it('leaves a verifiable chain with no gap after a batch of three thousand five hundred entries', function (): void {
    seedSubjects(3500);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $sequences = DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all();

    expect(DB::table(auditsTable())->count())->toBe(3501)
        ->and($sequences)->toBe(range(1, 3501))
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('leaves the frozen entries reproducing their own hashes with a mass batch beside them', function (): void {
    seedTheFrozenTrail();
    seedSubjects(2);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    foreach (GoldenLedger::entries() as [$attributes, $canonical, $hash]) {
        $frozen = new Audit()->forceFill($attributes);

        expect(new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($frozen)))->toBe($canonical)
            ->and(hasher()->hash($frozen))->toBe($hash);
    }
});

it('writes the batch at payload version one, the same one the frozen entries were sealed at', function (): void {
    seedTheFrozenTrail();
    seedSubjects(2);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    expect(DB::table(auditsTable())->distinct()->count('payload_version'))->toBe(1)
        ->and(DB::table(auditsTable())->where('payload_version', 1)->count())
        ->toBe(DB::table(auditsTable())->count());
});

it('gives the batch a chain of its own with no gap in it', function (): void {
    seedSubjects(3);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $entries = DB::table(auditsTable())->orderBy('sequence')->get();

    expect($entries->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and($entries->pluck('previous_hash')->all())
        ->toBe([null, $entries[0]->hash, $entries[1]->hash, $entries[2]->hash])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('records a long set as its size and a sample of it, never as the list', function (): void {
    config()->set('sentinel.mass_operations.sample', 3);
    seedSubjects(40);

    $identifiers = range(1, 40);

    AuditedSubject::query()->whereIn('id', $identifiers)->auditing()->update(['status' => 'archived']);

    $clause = massEntries()[0]->criteria['wheres'][0] ?? [];

    expect($clause['count'] ?? null)->toBe(40)
        ->and($clause['values'] ?? null)->toBe([1, 2, 3]);
});

it('records a raw fragment as its shape, with none of the literals inside it', function (): void {
    seedSubjects(3);

    AuditedSubject::query()
        ->whereRaw("email like 'subject%@example.com'")
        ->auditing()
        ->update(['status' => 'archived']);

    $written = json_encode(DB::table(auditsTable())->get()->all());

    expect(withSortedKeys(massEntries()[0]->criteria ?? []))->toBe(withSortedKeys(['wheres' => [['type' => 'raw', 'boolean' => 'and']]]))
        ->and($written)->toBeString()->not->toContain('subject%');
});

it('leaves no declared value in the clear in any column, criteria included', function (): void {
    DB::table('fixture_audited_subjects')->insert([
        ['name' => 'Ada', 'email' => 'ada@example.com', 'secret' => 'launch codes'],
        ['name' => 'Grace', 'email' => 'grace@example.com', 'secret' => 'launch codes'],
    ]);

    ProtectedSubject::query()
        ->where('email', 'ada@example.com')
        ->orWhere('secret', 'launch codes')
        ->auditing('individual')
        ->update(['name' => 'redacted']);

    $written = json_encode(DB::table(auditsTable())->get()->all());

    expect($written)->toBeString()
        ->not->toContain('ada@example.com')
        ->not->toContain('grace@example.com')
        ->not->toContain('launch codes');
});

it('records an upsert as what the engine reported, whichever engine it is', function (): void {
    seedSubjects(2);

    AuditedSubject::query()->auditing()->upsert(
        [['id' => 1, 'name' => 'Ada'], ['id' => 3, 'name' => 'Grace']],
        ['id'],
        ['name'],
    );

    $entry = massEntries()[0];

    expect($entry->criteria['rows'] ?? null)->toBe(2)
        ->and($entry->criteria['columns'] ?? null)->toBe(['id', 'name'])
        ->and($entry->affected_rows)->toBeGreaterThan(0)
        ->and(AuditedSubject::query()->count())->toBe(3);
});

it('lands the batch once and only once when the flush is retried', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    seedSubjects(3);

    AuditedSubject::query()->auditing('individual')->update(['status' => 'archived']);

    $waiting = app(Buffer::class)->take(100);

    app(Buffer::class)->putBack($waiting);
    app(Flusher::class)->flush();

    app(Buffer::class)->putBack($waiting);
    app(Flusher::class)->flush();

    expect(massEntries())->toHaveCount(4)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue()
        ->and(Audit::query()->distinct()->count('capture_id'))->toBe(4);
});
