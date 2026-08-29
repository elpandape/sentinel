<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\FakeQueueJob;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\NullOutput;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\runtime;

it('sees no request in a console process', function (): void {
    expect(runtime()->request())->toBeNull();
});

it('sees the request once one is bound', function (): void {
    httpRequest('/invoices/500');

    expect(runtime()->request()?->path())->toBe('invoices/500');
});

it('remembers the command it entered until it leaves', function (): void {
    $runtime = runtime();
    $runtime->enteredCommand('invoices:close', ['--force' => true]);

    expect($runtime->command())->toBe('invoices:close')
        ->and($runtime->arguments())->toBe(['--force' => true]);

    $runtime->leftCommand();

    expect($runtime->command())->toBeNull()
        ->and($runtime->arguments())->toBeEmpty();
});

it('remembers the job it entered until it leaves', function (): void {
    $runtime = runtime();
    $job = new FakeQueueJob('App\\Jobs\\CloseInvoices', [], 'invoices', 2);

    $runtime->enteredJob($job);

    expect($runtime->job())->toBe($job);

    $runtime->leftJob();

    expect($runtime->job())->toBeNull();
});

it('latches the scheduler', function (): void {
    $runtime = runtime();

    expect($runtime->scheduled())->toBeFalse();

    $runtime->enteredSchedule();

    expect($runtime->scheduled())->toBeTrue();
});

it('marks an audit write only for as long as it lasts', function (): void {
    $runtime = runtime();

    expect($runtime->writingAudit())->toBeFalse()
        ->and($runtime->whileWritingAudit(static fn (): bool => runtime()->writingAudit()))->toBeTrue()
        ->and($runtime->writingAudit())->toBeFalse();
});

it('hands the marker back even when the write throws', function (): void {
    $runtime = runtime();

    rescue(static fn (): mixed => $runtime->whileWritingAudit(static fn (): never => throw new RuntimeException('down')), report: false);

    expect($runtime->writingAudit())->toBeFalse();
});

it('gives an inner command back the one it started inside', function (): void {
    $runtime = runtime();

    $runtime->enteredCommand('invoices:close', ['month' => '2026-08']);
    $runtime->enteredCommand('sentinel:flush', []);

    expect($runtime->command())->toBe('sentinel:flush')
        ->and($runtime->arguments())->toBeEmpty();

    $runtime->leftCommand();

    expect($runtime->command())->toBe('invoices:close')
        ->and($runtime->arguments())->toBe(['month' => '2026-08']);
});

it('gives an inner job back the one it started inside', function (): void {
    $runtime = runtime();
    $outer = new FakeQueueJob('App\\Jobs\\CloseInvoices', [], 'invoices', 1);
    $inner = new FakeQueueJob('App\\Jobs\\SettleAudit', [], 'audits', 1);

    $runtime->enteredJob($outer);
    $runtime->enteredJob($inner);

    expect($runtime->job())->toBe($inner);

    $runtime->leftJob();

    expect($runtime->job())->toBe($outer);
});

it('holds the identifier the middleware assigned', function (): void {
    $runtime = runtime();

    expect($runtime->requestId())->toBeNull();

    $runtime->assignRequestId('01j0000000000000000000000');

    expect($runtime->requestId())->toBe('01j0000000000000000000000');
});

it('latches the request the router dispatched', function (): void {
    Route::get('/invoices/{invoice}', function (): string {
        expect(runtime()->request()?->path())->toBe('invoices/500');

        return 'ok';
    });

    $this->get('/invoices/500')->assertOk();
});

it('latches the command artisan started and lets go when it finishes', function (): void {
    $input = new ArrayInput(['month' => '2026-08']);
    $input->bind(new InputDefinition([new InputArgument('month')]));

    event(new CommandStarting('invoices:close', $input, new NullOutput));

    expect(runtime()->command())->toBe('invoices:close')
        ->and(runtime()->arguments())->toHaveKey('month');

    event(new CommandFinished('invoices:close', $input, new NullOutput, 0));

    expect(runtime()->command())->toBeNull();
});

it('latches the job the worker picked up and lets go when it is done', function (): void {
    $job = new FakeQueueJob('App\\Jobs\\CloseInvoices', [], 'invoices', 1);

    event(new JobProcessing('redis', $job));

    expect(runtime()->job())->toBe($job);

    event(new JobProcessed('redis', $job));

    expect(runtime()->job())->toBeNull();
});

it('latches the task the scheduler started', function (): void {
    event(new ScheduledTaskStarting(app(Schedule::class)->command('invoices:close')));

    expect(runtime()->scheduled())->toBeTrue();
});
