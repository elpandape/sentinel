<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\MemoryLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\checkpoints;
use function ElPandaPe\Sentinel\Tests\fanout;
use function ElPandaPe\Sentinel\Tests\ledger;

beforeEach(function (): void {
    config()->set('sentinel.integrity.checkpoints.every', 3);
});

it('anchors nothing at all with the default configuration', function (): void {
    ledger()->writeMany([auditData(), auditData(), auditData()]);

    expect(checkpoints()->of('global'))->toBeEmpty();
});

it('anchors the window the write completed, once anchoring is on', function (): void {
    config()->set('sentinel.integrity.checkpoints.enabled', true);

    ledger()->writeMany([auditData(), auditData(), auditData()]);

    expect(checkpoints()->of('global'))->toHaveCount(1)
        ->and(checkpoints()->last('global')?->to)->toBe(3);
});

it('anchors nothing until the window fills', function (): void {
    config()->set('sentinel.integrity.checkpoints.enabled', true);

    ledger()->writeMany([auditData(), auditData()]);

    expect(checkpoints()->of('global'))->toBeEmpty();

    ledger()->write(auditData());

    expect(checkpoints()->of('global'))->toHaveCount(1);
});

it('anchors each stream on its own count', function (): void {
    config()->set('sentinel.integrity.checkpoints.enabled', true);

    ledger()->writeMany([
        auditData(['stream' => 'alpha']),
        auditData(['stream' => 'alpha']),
        auditData(['stream' => 'alpha']),
        auditData(['stream' => 'beta']),
    ]);

    expect(checkpoints()->of('alpha'))->toHaveCount(1)
        ->and(checkpoints()->of('beta'))->toBeEmpty();
});

it('anchors nothing through a ledger that keeps nothing to anchor', function (): void {
    config()->set('sentinel.integrity.checkpoints.enabled', true);

    app(MemoryLedger::class)->writeMany([auditData(), auditData(), auditData()]);

    expect(checkpoints()->of('global'))->toBeEmpty();
});

it('anchors through the primary that sealed the entries when writing to more than one place', function (): void {
    config()->set('sentinel.integrity.checkpoints.enabled', true);

    fanout(ledger(), [app(MemoryLedger::class)])->writeMany([auditData(), auditData(), auditData()]);

    expect(checkpoints()->of('global'))->toHaveCount(1);
});
