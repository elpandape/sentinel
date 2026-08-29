<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

use function ElPandaPe\Sentinel\Tests\runtime;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 500);
});

it('settles what the buffer is holding and says how much', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    $this->artisan('sentinel:flush')
        ->expectsOutputToContain('Settled 2 entries')
        ->assertExitCode(Command::SUCCESS);

    expect(Audit::query()->count())->toBe(2)
        ->and(app(Buffer::class)->size())->toBe(0)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('settles nothing without complaining when there is nothing waiting', function (): void {
    $this->artisan('sentinel:flush')
        ->expectsOutputToContain('Settled 0 entries')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a mode that has no buffer, naming the one it found', function (): void {
    config()->set('sentinel.mode', 'sync');

    $this->artisan('sentinel:flush')
        ->expectsOutputToContain('writing in sync mode')
        ->assertExitCode(Command::INVALID);
});

it('gives back a failure the caller can see, and keeps what it could not settle', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->instance(Ledger::class, new FailingLedger);

    $this->artisan('sentinel:flush')
        ->expectsOutputToContain('could not be settled')
        ->assertExitCode(Command::FAILURE);

    expect(app(Buffer::class)->size())->toBe(1)
        ->and(Audit::query()->count())->toBe(0);
});

it('settles the same buffer once when it is run twice over', function (): void {
    foreach (range(1, 6) as $n) {
        new AuditedSubject()->forceFill(['name' => 'subject-'.$n])->save();
    }

    $this->artisan('sentinel:flush')->assertExitCode(Command::SUCCESS);
    $this->artisan('sentinel:flush')->assertExitCode(Command::SUCCESS);

    expect(Audit::query()->count())->toBe(6)
        ->and(Audit::query()->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, 6));
});

it('speaks the language the application is set to', function (): void {
    app()->setLocale('es');

    $this->artisan('sentinel:flush')->expectsOutputToContain('Se asentaron 0 entradas');
});

it('describes itself in the language the application is set to', function (): void {
    app()->setLocale('es');

    expect(app(Kernel::class)->all()['sentinel:flush']->getDescription())
        ->toContain('Asienta todo lo que el buffer');
});

it('gives an outer command back its own runtime', function (): void {
    runtime()->enteredCommand('invoices:close', ['month' => '2026-08']);

    $this->artisan('sentinel:flush')->run();

    expect(runtime()->command())->toBe('invoices:close')
        ->and(runtime()->arguments())->toBe(['month' => '2026-08']);
});
