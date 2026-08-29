<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\FailurePolicy;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    app()->instance(Ledger::class, new FailingLedger);
});

it('lets a failed write take the request down by default', function (): void {
    expect(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save())
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('lets the request through when the policy says to record it instead', function (): void {
    app(Repository::class)->set('sentinel.on_write_failure', 'log');

    $record = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $record->save();

    expect($record->fresh()?->name)->toBe('Ada')
        ->and(Audit::query()->count())->toBe(0);
});

it('records a swallowed failure through the configured channel', function (): void {
    app(Repository::class)->set('sentinel.on_write_failure', 'log');
    app(Repository::class)->set('sentinel.log_channel', 'stack');

    Log::shouldReceive('channel')->once()->with('stack')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, FailingLedger::REASON)
        && $context['subject_type'] === AuditedSubject::class
        && ! array_key_exists('before', $context));

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
});

it("records nothing when the failure is the caller's to catch", function (): void {
    Log::shouldReceive('channel')->never();

    rescue(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save(), report: false);
});

it('says which entry did not land, on either policy', function (string $policy): void {
    app(Repository::class)->set('sentinel.on_write_failure', $policy);

    $failed = null;

    app(Dispatcher::class)->listen(AuditWriteFailed::class, static function (AuditWriteFailed $event) use (&$failed): void {
        $failed = $event;
    });

    rescue(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save(), report: false);

    expect($failed?->event)->toBe('created')
        ->and($failed?->subjectType)->toBe(AuditedSubject::class)
        ->and($failed?->failure->getMessage())->toBe(FailingLedger::REASON);
})->with(['throw', 'log']);

it('never lets a deferred failure out of a transaction that already committed', function (): void {
    $failed = null;

    app(Dispatcher::class)->listen(AuditWriteFailed::class, static function (AuditWriteFailed $event) use (&$failed): void {
        $failed = $event;
    });

    DB::transaction(static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    expect($failed)->toBeInstanceOf(AuditWriteFailed::class);
});

it('forces the failure through under compliance, whatever the setting says', function (): void {
    expect(sentinelConfig(['on_write_failure' => 'log', 'compliance' => true])->writeFailurePolicy())
        ->toBe(FailurePolicy::Throw);
});

it('refuses a policy it does not know', function (): void {
    sentinelConfig(['on_write_failure' => 'shrug'])->writeFailurePolicy();
})->throws(ConfigurationException::class, 'throw, log');

it('reads the channel as nothing when none is named', function (): void {
    expect(sentinelConfig(['log_channel' => null])->logChannel())->toBeNull();
});
