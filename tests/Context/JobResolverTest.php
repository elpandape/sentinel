<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\JobResolver;
use ElPandaPe\Sentinel\Tests\Fixtures\FakeQueueJob;

use function ElPandaPe\Sentinel\Tests\runtime;

it('resolves nothing outside a job', function (): void {
    expect(app(JobResolver::class)->resolve())->toBeEmpty();
});

it('names the job, its queue and how many attempts it has taken', function (): void {
    runtime()->enteredJob(new FakeQueueJob('App\\Jobs\\CloseInvoices', [], 'invoices', 3));

    expect(app(JobResolver::class)->resolve())->toBe([
        'job' => 'App\\Jobs\\CloseInvoices',
        'queue' => 'invoices',
        'attempts' => 3,
    ]);
});

it('adds the batch id when the job carries one', function (): void {
    runtime()->enteredJob(new FakeQueueJob('App\\Jobs\\CloseInvoices', ['batchId' => 'batch-1'], 'invoices', 1));

    expect(app(JobResolver::class)->resolve())->toBe([
        'job' => 'App\\Jobs\\CloseInvoices',
        'queue' => 'invoices',
        'attempts' => 1,
        'batch_id' => 'batch-1',
    ]);
});
