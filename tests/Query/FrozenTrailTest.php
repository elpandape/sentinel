<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;

use function ElPandaPe\Sentinel\Tests\seedTheFrozenTrail;

beforeEach(function (): void {
    $this->frozen = seedTheFrozenTrail();
});

it('reads back every entry payload version one wrote, in the order it wrote them', function (): void {
    expect(Sentinel::audits()->get()->pluck('id')->all())->toBe($this->frozen);
});

it('hands back rows that still reproduce the hashes they were frozen with', function (): void {
    $frozen = array_column(array_map(
        static fn (array $entry): array => ['id' => $entry[0]['id'], 'hash' => $entry[2]],
        array_values(GoldenLedger::entries()),
    ), 'hash', 'id');

    Sentinel::audits()->get()->each(function (Audit $audit) use ($frozen): void {
        expect($audit->hash)->toBe($frozen[$audit->id])
            ->and($audit->verifyIntegrity())->toBeTrue();
    });
});

it('reaches a frozen entry through each filter that describes it', function (Closure $narrow, array $expected): void {
    expect($narrow(Sentinel::audits())->get()->pluck('id')->all())->toBe($expected);
})->with([
    'the tenant three entries carried' => [
        fn (): mixed => Sentinel::audits()->forTenant('acme'),
        ['01JGOLDEN000000000000000B2', '01JGOLDEN000000000000000F6', '01JGOLDEN000000000000000H8'],
    ],
    'the only severity that was critical' => [
        fn (): mixed => Sentinel::audits()->whereSeverity(Severity::Critical),
        ['01JGOLDEN000000000000000C3'],
    ],
    'the only entry that came from a command' => [
        fn (): mixed => Sentinel::audits()->whereSource(Source::Cli),
        ['01JGOLDEN000000000000000C3'],
    ],
    'the subject two entries share' => [
        fn (): mixed => Sentinel::audits()->for('subject', 1),
        ['01JGOLDEN000000000000000D4', '01JGOLDEN000000000000000E5'],
    ],
    'the actor the entries that had one share' => [
        fn (): mixed => Sentinel::audits()->by('user', 1),
        ['01JGOLDEN000000000000000B2', '01JGOLDEN000000000000000F6', '01JGOLDEN000000000000000H8'],
    ],
    'the trace that reaches both sides of the queue' => [
        fn (): mixed => Sentinel::audits()->withTrace('4bf92f3577b34da6a3ce929d0e0e4736'),
        ['01JGOLDEN000000000000000F6', '01JGOLDEN000000000000000H8'],
    ],
    'the operation the worker continued' => [
        fn (): mixed => Sentinel::audits()->inTransaction('01JTRANSACTION000000000000'),
        ['01JGOLDEN000000000000000H8'],
    ],
]);

it('reads the frozen trail newest first without touching a single row', function (): void {
    $before = DB::table('sentinel_audits')->orderBy('id')->pluck('hash')->all();

    $found = Sentinel::audits()->latest()->get();

    expect($found->pluck('id')->all())->toBe(array_reverse($this->frozen))
        ->and(DB::table('sentinel_audits')->orderBy('id')->pluck('hash')->all())->toBe($before);
});
