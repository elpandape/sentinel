<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\CheckpointCommand;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\checkpoints;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;

beforeEach(function (): void {
    seedTheReferenceChain();
    config()->set('sentinel.integrity.checkpoints.every', 4);
});

it('anchors every complete window the streams still owe', function (): void {
    $this->artisan('sentinel:checkpoint')
        ->expectsOutputToContain(ReferenceChain::STREAM)
        ->assertExitCode(0);

    expect(checkpoints()->of(ReferenceChain::STREAM))->toHaveCount(2);
});

it('anchors only the stream it was pointed at', function (): void {
    config()->set('sentinel.integrity.checkpoints.every', 2);

    $this->artisan('sentinel:checkpoint', ['--stream' => ReferenceChain::FORK])->assertExitCode(0);

    expect(checkpoints()->of(ReferenceChain::FORK))->toHaveCount(1)
        ->and(checkpoints()->of(ReferenceChain::STREAM))->toBeEmpty();
});

it('says there was nothing to anchor rather than failing', function (): void {
    anchor(ReferenceChain::STREAM, 4);
    config()->set('sentinel.integrity.checkpoints.every', 4);

    $this->artisan('sentinel:checkpoint', ['--stream' => ReferenceChain::STREAM])
        ->expectsOutputToContain('Nothing left to anchor')
        ->assertExitCode(0);
});

it('refuses a ledger that cannot name the chains it holds', function (): void {
    app()->bind(Ledger::class, NullLedger::class);

    $this->artisan('sentinel:checkpoint')->assertExitCode(2);
});

it('describes itself in the language the application is set to', function (): void {
    app()->setLocale('es');

    expect(app(CheckpointCommand::class)->getDescription())
        ->toBe('Ancla todas las ventanas completas que los streams tengan pendientes');
});

it('speaks the language the application is set to', function (): void {
    anchor(ReferenceChain::STREAM, 4);
    config()->set('sentinel.integrity.checkpoints.every', 4);
    app()->setLocale('es');

    $this->artisan('sentinel:checkpoint', ['--stream' => ReferenceChain::STREAM])
        ->expectsOutputToContain('No queda nada que anclar');
});
