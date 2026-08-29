<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\SourceResolver;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\FakeQueueJob;
use Illuminate\Http\Request;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\runtime;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('resolves to queue while the runtime is writing an audit entry', function (): void {
    $source = runtime()->whileWritingAudit(static fn (): Source => app(SourceResolver::class)->resolve()['source']);

    expect($source)->toBe(Source::Queue);
});

it('resolves to job when the runtime holds a job', function (): void {
    runtime()->enteredJob(new FakeQueueJob('App\\Jobs\\CloseInvoices', [], 'invoices', 1));

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Job);
});

it('resolves to scheduler when the runtime is scheduled', function (): void {
    runtime()->enteredSchedule();

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Scheduler);
});

it('resolves to scheduler for every schedule command name', function (string $command): void {
    runtime()->enteredCommand($command, []);

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Scheduler);
})->with(['schedule:run', 'schedule:work', 'schedule:finish']);

it('resolves to api when the request matches the boundary', function (): void {
    httpRequest('/api/invoices');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Api);
});

it('resolves to http when the request does not match the boundary', function (): void {
    httpRequest('/invoices');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Http);
});

it('resolves to cli when a command is running without a request', function (): void {
    runtime()->enteredCommand('invoices:close', []);

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Cli);
});

it('resolves to system when nothing else is running but the unit test runner', function (): void {
    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::System);
});

it('resolves to console outside a request, a command and the unit test runner', function (): void {
    app()->instance('env', 'production');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Console);
});

it('lets a closure boundary decide between api and http', function (): void {
    sentinelConfig(['resolvers.request.api' => fn (Request $request): bool => $request->is('internal/*')]);

    httpRequest('/internal/reports');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Api);

    httpRequest('/public/reports');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Http);
});

it('throws when the boundary closure does not return a boolean', function (): void {
    sentinelConfig(['resolvers.request.api' => fn (Request $request): string => 'nope']);

    httpRequest('/reports');

    expect(fn (): mixed => app(SourceResolver::class)->resolve())
        ->toThrow(ConfigurationException::class, 'resolvers.request.api');
});

it('resolves the request over a command running at the same time', function (): void {
    runtime()->enteredCommand('inspire', []);
    httpRequest('/api/reports');

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Api);
});
