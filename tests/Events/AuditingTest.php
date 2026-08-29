<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Contracts\Events\Dispatcher;

use function ElPandaPe\Sentinel\Tests\auditsOf;

it('offers the entry once, at the end of the pass', function (): void {
    $seen = [];

    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event) use (&$seen): void {
        $seen[] = $event->audit->event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen)->toBe(['created']);
});

it('offers an entry holding nothing the ledger will not hold too', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event) use (&$seen): void {
        $seen = $event->audit->after;
    });

    new ProtectedSubject()->forceFill(['name' => 'Ada', 'email' => 'ada@example.com', 'secret' => 'hunter2'])->save();

    expect($seen['email'] ?? null)->toBe('a****a@e****e.c****m')
        ->and($seen['secret'] ?? null)->not->toBe('hunter2');
});

it('is never offered an entry a stage already dropped', function (): void {
    $record = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $record->save();

    $seen = 0;

    app(Dispatcher::class)->listen(Auditing::class, static function () use (&$seen): void {
        $seen++;
    });

    $record->update(['name' => 'Ada']);

    expect($seen)->toBe(0)
        ->and(Audit::query()->count())->toBe(1);
});

it('stops an entry a listener refuses, without spending a sequence', function (): void {
    app(Dispatcher::class)->listen(Auditing::class, static fn (): bool => false);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->count())->toBe(0);
});

it('sends a cancelled entry out the same door a stage sends one out', function (): void {
    $discarded = null;

    app(Dispatcher::class)->listen(Auditing::class, static fn (): bool => false);
    app(Dispatcher::class)->listen(AuditDiscarded::class, static function (AuditDiscarded $event) use (&$discarded): void {
        $discarded = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($discarded?->stage)->toBe(Auditing::class)
        ->and($discarded?->reason)->toBe(Auditing::REASON)
        ->and($discarded?->event)->toBe('created')
        ->and($discarded?->subjectType)->toBe(AuditedSubject::class);
});

it('says why in the words the listener chose', function (): void {
    $discarded = null;

    app(Dispatcher::class)->listen(Auditing::class, static function (): bool {
        app(Discard::class)->because('the season is closed');

        return false;
    });

    app(Dispatcher::class)->listen(AuditDiscarded::class, static function (AuditDiscarded $event) use (&$discarded): void {
        $discarded = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($discarded?->reason)->toBe('the season is closed')
        ->and($discarded?->message())->toBe('the season is closed');
});

it('reads a cancellation out loud in both languages', function (string $locale, string $line): void {
    app()->setLocale($locale);

    $event = new AuditDiscarded('model', 'created', 'App\\Models\\User', '7', Auditing::class, Auditing::REASON);

    expect($event->message())->toBe($line);
})->with([
    ['en', 'A listener cancelled the created entry for App\\Models\\User 7 before it reached the ledger.'],
    ['es', 'Un listener canceló el asiento created de App\\Models\\User 7 antes de que llegara al ledger.'],
]);

it('lets a listener change what the entry says', function (): void {
    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event): void {
        $event->audit->metadata = ['reviewed_by' => 'the listener'];
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->firstOrFail()->metadata)->toBe(['reviewed_by' => 'the listener']);
});

it('refuses to let a listener move the entry onto another subject', function (): void {
    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event): void {
        $event->audit->subject_type = AuditedSubject::class;
        $event->audit->subject_id = '999';
    });

    $record = new ProtectedSubject()->forceFill(['name' => 'Ada', 'email' => 'ada@example.com']);
    $record->save();

    $entry = auditsOf($record)->firstOrFail();

    expect($entry->subject_type)->toBe(ProtectedSubject::class)
        ->and($entry->subject_id)->toBe((string) $record->getKey())
        ->and($entry->after['email'] ?? null)->toBe('a****a@e****e.c****m');
});

it('keeps the window closed for whatever the listener did to a model of its own', function (): void {
    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event): void {
        if ($event->audit->event !== 'created') {
            new AuditedSubject()->forceFill(['name' => 'written from a listener'])->save();
        }
    });

    $record = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $record->save();
    $record->update(['name' => 'Grace']);

    expect(Audit::query()->count())->toBe(3)
        ->and(app(Discard::class)->running())->toBeFalse();
});

it('hands a paused recorder no entry to offer', function (): void {
    $seen = 0;

    app(Dispatcher::class)->listen(Auditing::class, static function () use (&$seen): void {
        $seen++;
    });

    Sentinel::withoutAuditing(static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    expect($seen)->toBe(0);
});

it('carries the entry the capture built, correlation included', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(Auditing::class, static function (Auditing $event) use (&$seen): void {
        $seen = $event->audit;
    });

    Sentinel::transaction('checkout', static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    expect($seen)->toBeInstanceOf(AuditData::class)
        ->and($seen?->transaction_id)->not->toBeNull();
});
