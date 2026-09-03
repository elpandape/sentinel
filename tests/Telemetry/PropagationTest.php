<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Telemetry\Envelope;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditingJob;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\queueTable;
use function ElPandaPe\Sentinel\Tests\runTheWorker;

const REQUEST_TRACE = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

beforeEach(function (): void {
    config()->set('sentinel.telemetry.enabled', true);
    config()->set('queue.default', 'database');
    queueTable();
});

it('seals the trace of the request into the payload of the job it queued', function (): void {
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    AuditingJob::dispatch();

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload)->toBeArray()
        ->and($payload['illuminate:log:context']['hidden'])->toHaveKey(Envelope::KEY);
});

it('gives the entry a worker writes the trace of the request that queued the job', function (): void {
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    AuditingJob::dispatch();

    runTheWorker();

    expect(Audit::query()->sole()->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('writes an entry with no trace at all for a job queued before the feature was on', function (): void {
    config()->set('sentinel.telemetry.enabled', false);

    AuditingJob::dispatch();

    config()->set('sentinel.telemetry.enabled', true);

    runTheWorker();

    expect(Audit::query()->sole()->trace_id)->toBeNull();
});

it('seals nothing while propagation is switched off', function (): void {
    config()->set('sentinel.telemetry.propagate_context', false);
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    AuditingJob::dispatch();

    runTheWorker();

    expect(Audit::query()->sole()->trace_id)->toBeNull();
});

it('carries the open business operation across the queue as well', function (): void {
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    $transaction = Sentinel::transaction('checkout', static function (): string {
        AuditingJob::dispatch();

        return (string) AuditTransaction::query()->sole()->id;
    });

    runTheWorker();

    expect(Audit::query()->sole()->transaction_id)->toBe($transaction);
});

it('settles a queued entry under the trace of the capture, not of the worker', function (): void {
    config()->set('sentinel.mode', 'queue');
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    runTheWorker();

    expect(Audit::query()->sole()->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('flushes a buffered entry under the trace of the capture, not of the flush', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'redis');
    config()->set('sentinel.buffer.size', 500);
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->forgetScopedInstances();
    Context::flush();

    app(Flusher::class)->flush();

    expect(Audit::query()->sole()->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('writes a synchronous entry under the trace of the request', function (): void {
    httpRequest('/invoices', ['traceparent' => REQUEST_TRACE]);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->sole()->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});
