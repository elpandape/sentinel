<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\AltekTrail;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenImports;
use ElPandaPe\Sentinel\Tests\Fixtures\OwenItTrail;

use function ElPandaPe\Sentinel\Tests\altek;
use function ElPandaPe\Sentinel\Tests\frozen;
use function ElPandaPe\Sentinel\Tests\owenIt;

/**
 * The mapping is the contract with the packages a trail comes from, and one that drifts does not
 * fail: it imports, and what lands means something else. These are the numbers to look at when one
 * stops matching — either the source moved and the guide has to say so, or this did and should not
 * have.
 */
it('still reads every owen-it row the way it was frozen to', function (): void {
    foreach (OwenItTrail::rows() as $row) {
        expect(frozen(owenIt(), $row))->toBe(GoldenImports::owenIt()[(string) $row['id']]);
    }
});

it('still reads every altek row the way it was frozen to', function (): void {
    foreach (AltekTrail::rows() as $row) {
        expect(frozen(altek(), $row))->toBe(GoldenImports::altek()[(string) $row['id']]);
    }
});

it('freezes every row of every dump, so a row appearing or vanishing shows up here', function (): void {
    expect(array_keys(GoldenImports::owenIt()))->toBe(array_column(OwenItTrail::rows(), 'id'))
        ->and(array_keys(GoldenImports::altek()))->toBe(array_column(AltekTrail::rows(), 'id'));
});

it('freezes a row an origin refuses as the refusal it is, because whether it reads at all is the mapping too', function (): void {
    expect(GoldenImports::owenIt()['4'])->toHaveKey('refused')
        ->and(GoldenImports::altek()['3'])->toHaveKey('refused');
});

it('gives the two origins two identities for the same row key', function (): void {
    expect(GoldenImports::owenIt()['1']['capture_id'])->not->toBe(GoldenImports::altek()['1']['capture_id']);
});

it('freezes what the source decides and never what the write does', function (): void {
    expect(GoldenImports::owenIt()['1'])->not->toHaveKeys(['id', 'sequence', 'hash', 'previous_hash', 'created_at'])
        ->and(GoldenImports::owenIt()['1'])->toHaveKeys(['occurred_at', 'capture_id', 'source']);
});
