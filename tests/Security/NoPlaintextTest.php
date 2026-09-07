<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\SecretiveSubject;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\eventPayloads;
use function ElPandaPe\Sentinel\Tests\recordedEvents;
use function ElPandaPe\Sentinel\Tests\recordEveryEvent;

/**
 * The central test of this version. Everything else asserts that one mechanism does its
 * job; this one sweeps the persisted row and the payload of every event that was
 * dispatched, looking for the values that must not have survived.
 */
beforeEach(function (): void {
    recordEveryEvent();
});

it('leaves no declared value in the clear, in any column of any row', function (): void {
    $subject = SecretiveSubject::query()->create([
        'name' => 'Ada',
        'status' => SecretiveSubject::EXCLUDED,
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $subject->update([
        'status' => SecretiveSubject::EXCLUDED.'-again',
        'email' => SecretiveSubject::REDACTED.'-again',
        'secret' => SecretiveSubject::ENCRYPTED.'-again',
        'price' => SecretiveSubject::HASHED.'-again',
    ]);

    $subject->delete();

    $written = json_encode(DB::table(auditsTable())->get()->all());

    expect($written)->toBeString()
        ->not->toContain(SecretiveSubject::EXCLUDED)
        ->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::ENCRYPTED)
        ->not->toContain(SecretiveSubject::HASHED);
});

it('leaves no declared value in the payload of any event it dispatched', function (): void {
    $subject = SecretiveSubject::query()->create([
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $subject->update(['name' => 'Grace']);

    expect(eventPayloads())->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::ENCRYPTED)
        ->not->toContain(SecretiveSubject::HASHED);
});

it('dispatched something, so the sweep is looking at more than an empty log', function (): void {
    $subject = SecretiveSubject::query()->create(['email' => SecretiveSubject::REDACTED]);

    $subject->update(['status' => SecretiveSubject::EXCLUDED]);

    expect(recordedEvents())->toBeGreaterThan(0);
});

it('leaves no declared value in the payload of a discard either', function (): void {
    $subject = SecretiveSubject::query()->create(['email' => SecretiveSubject::REDACTED]);

    $subject->update(['status' => SecretiveSubject::EXCLUDED]);

    expect(eventPayloads())->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::EXCLUDED);
});

it('still records that the protected fields changed', function (): void {
    $subject = SecretiveSubject::query()->create([
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $subject->update([
        'email' => SecretiveSubject::REDACTED.'-again',
        'secret' => SecretiveSubject::ENCRYPTED.'-again',
        'price' => SecretiveSubject::HASHED.'-again',
    ]);

    $paths = array_column($subject->latestAudit()?->changes ?? [], 'path');

    expect($paths)->toContain('/email', '/secret', '/price');
});

it('keeps the excluded field out of the entry rather than transforming it', function (): void {
    $subject = SecretiveSubject::query()->create(['status' => SecretiveSubject::EXCLUDED, 'name' => 'Ada']);

    expect($subject->latestAudit()?->after)->not->toHaveKey('status')
        ->toHaveKey('name');
});

it('names in the entry which fields it encrypted', function (): void {
    $subject = SecretiveSubject::query()->create(['secret' => SecretiveSubject::ENCRYPTED]);

    expect($subject->latestAudit()?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default']);
});

it('leaves no declared value in the payload of the job that carries it to the ledger', function (): void {
    config()->set('sentinel.mode', 'queue');

    Bus::fake();

    SecretiveSubject::query()->create([
        'name' => 'Ada',
        'status' => SecretiveSubject::EXCLUDED,
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $carried = serialize(Bus::dispatched(SettleAudit::class)->all());

    expect($carried)->toContain('SettleAudit')
        ->not->toContain(SecretiveSubject::EXCLUDED)
        ->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::ENCRYPTED)
        ->not->toContain(SecretiveSubject::HASHED);
});

it('leaves no declared value in what the buffer holds until it flushes', function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 100);

    SecretiveSubject::query()->create([
        'name' => 'Ada',
        'status' => SecretiveSubject::EXCLUDED,
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    $held = serialize(app(Buffer::class)->take(100));

    expect($held)->toContain('AuditData')
        ->not->toContain(SecretiveSubject::EXCLUDED)
        ->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::ENCRYPTED)
        ->not->toContain(SecretiveSubject::HASHED);
});

it('leaves no declared value in the line it logs when a write fails', function (): void {
    app()->instance(Ledger::class, new FailingLedger);

    config()->set('sentinel.on_write_failure', 'log');

    $logged = '';

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->withArgs(function (string $message, array $context) use (&$logged): bool {
        $failure = $context['exception'] ?? null;

        $logged = $message
            .json_encode(array_diff_key($context, ['exception' => null]))
            .($failure instanceof Throwable ? $failure->getMessage() : '');

        return true;
    });

    SecretiveSubject::query()->create([
        'name' => 'Ada',
        'status' => SecretiveSubject::EXCLUDED,
        'email' => SecretiveSubject::REDACTED,
        'secret' => SecretiveSubject::ENCRYPTED,
        'price' => SecretiveSubject::HASHED,
    ]);

    expect($logged)->toContain(FailingLedger::REASON)
        ->not->toContain(SecretiveSubject::EXCLUDED)
        ->not->toContain(SecretiveSubject::REDACTED)
        ->not->toContain(SecretiveSubject::ENCRYPTED)
        ->not->toContain(SecretiveSubject::HASHED);
});
