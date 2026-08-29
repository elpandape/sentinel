<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Events\AuditRestored;
use ElPandaPe\Sentinel\Events\AuditRestoring;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Restore\Restorer;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SoftDeletingSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\refuseToSave;
use function ElPandaPe\Sentinel\Tests\restorableEntry;

it('puts the whole recorded state back after a run of changes', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'status' => 'open']);
    $record->update(['email' => 'grace@example.com']);
    $record->update(['status' => 'closed']);
    $record->update(['name' => 'Grace']);

    $opening = auditsOf($record)->first();
    $result = $opening->restore();

    expect($record->fresh())
        ->name->toBe('Ada')
        ->email->toBe('ada@example.com')
        ->status->toBe('open')
        ->and($result->applied)->toBe(['email', 'name', 'status'])
        ->and($result->skipped)->toBe(['id' => Omission::IdentityField]);
});

it('moves only the fields it was asked for and leaves the rest where they are', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $entry = auditsOf($record)->first();
    $record->update(['name' => 'Grace', 'email' => 'grace@example.com']);

    $entry->restore(['email']);

    expect($record->fresh())->name->toBe('Grace')->email->toBe('ada@example.com');
});

it('writes exactly one entry, of its own kind, pointing back at the one it came from', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $result = $opening->restore();
    $written = auditsOf($record)->last();

    expect(auditsOf($record))->toHaveCount(3)
        ->and($written->audit_type)->toBe(Restorer::AUDIT_TYPE)
        ->and($written->event)->toBe('restore')
        ->and($written->source_audit_id)->toBe($opening->id)
        ->and($written->id)->toBe($result->entry?->id)
        ->and($written->diff()->toArray())->toBe([
            ['path' => '/name', 'op' => 'replace', 'old' => 'Grace', 'new' => 'Ada'],
        ]);
});

it('seals what it applied and what it declined inside the entry', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $opening->restore();

    expect(auditsOf($record)->last()->metadata)->toBe([
        'restore' => ['applied' => ['name'], 'skipped' => [['field' => 'id', 'reason' => 'identity_field']]],
    ]);
});

it('never lets a protected field name a key of what it seals', function (): void {
    $record = ProtectedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'secret' => 'hunter2']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace', 'email' => 'grace@example.com']);

    $opening->restore();

    expect(auditsOf($record)->last()->metadata)->toBe([
        'restore' => [
            'applied' => ['name'],
            'skipped' => [
                ['field' => 'email', 'reason' => 'redacted_field'],
                ['field' => 'id', 'reason' => 'identity_field'],
                ['field' => 'secret', 'reason' => 'hashed_field'],
            ],
        ],
    ]);
});

it('restores the same entry twice without putting an empty link in the chain', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $opening->restore();
    $again = $opening->restore();

    expect($again->applied)->toBeEmpty()
        ->and($again->entry)->toBeNull()
        ->and($again->reason('name'))->toBe(Omission::Unchanged)
        ->and(auditsOf($record))->toHaveCount(3);
});

it('leaves the record untouched when a listener cancels the restoration', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    app(Dispatcher::class)->listen(AuditRestoring::class, static fn (): bool => false);

    $result = $opening->restore();

    expect($result->refused)->toBe(Omission::Cancelled)
        ->and($record->fresh()->name)->toBe('Grace')
        ->and(auditsOf($record))->toHaveCount(2);
});

it('tells a listener which keys are about to move, before they move', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada', 'status' => 'open']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $seen = [];

    app(Dispatcher::class)->listen(AuditRestoring::class, static function (AuditRestoring $event) use (&$seen): void {
        $seen = [$event->applying, $event->subject->getAttribute('name'), $event->relation];
    });

    $opening->restore();

    expect($seen)->toBe([['name'], 'Grace', null]);
});

it('announces the restoration once it is true, with the result closed', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $announced = null;

    app(Dispatcher::class)->listen(AuditRestored::class, static function (AuditRestored $event) use (&$announced): void {
        $announced = $event;
    });

    $opening->restore();

    expect($announced?->result->applied)->toBe(['name'])
        ->and($announced?->result->entry)->toBeInstanceOf(Audit::class)
        ->and($announced?->entry->id)->toBe($opening->id);
});

it('waits for the transaction of the application before it announces anything', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $announced = null;
    $returned = null;

    app(Dispatcher::class)->listen(AuditRestored::class, static function (AuditRestored $event) use (&$announced): void {
        $announced = $event;
    });

    DB::transaction(static function () use ($opening, &$returned, &$announced): void {
        $returned = $opening->restore();

        expect($announced)->toBeNull();
    });

    expect($returned?->entry)->toBeNull()
        ->and($announced?->result->entry)->toBeInstanceOf(Audit::class)
        ->and($announced?->result->applied)->toBe(['name']);
});

it('announces nothing at all when the transaction of the application rolls back', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    $announced = null;

    app(Dispatcher::class)->listen(AuditRestored::class, static function (AuditRestored $event) use (&$announced): void {
        $announced = $event;
    });

    rescue(static function () use ($opening): void {
        DB::transaction(static function () use ($opening): void {
            $opening->restore();

            throw new RuntimeException('undo');
        });
    }, report: false);

    expect($announced)->toBeNull()
        ->and($record->fresh()->name)->toBe('Grace');
});

it('rolls the whole thing back when applying it fails halfway', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    refuseToSave(AuditedSubject::class);

    expect(static fn (): mixed => $opening->restore())->toThrow(RuntimeException::class)
        ->and($record->fresh()->name)->toBe('Grace')
        ->and(auditsOf($record))->toHaveCount(2);
});

it('does not leave auditing paused when applying it fails', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    refuseToSave(AuditedSubject::class);

    rescue(static fn (): mixed => $opening->restore(), report: false);

    Sentinel::event('after-the-failure')->record();

    expect(Sentinel::audits()->whereEvent('after-the-failure')->get())->toHaveCount(1);
});

it('keeps the chain verifying on both sides of a restoration', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    expect(Sentinel::verifyIntegrity($opening->stream)->isIntact())->toBeTrue();

    $opening->restore();

    expect(Sentinel::verifyIntegrity($opening->stream)->isIntact())->toBeTrue()
        ->and(auditsOf($record)->last()->verifyIntegrity())->toBeTrue();
});

it('takes the identifier of the business operation it runs inside', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $opening = auditsOf($record)->first();
    $record->update(['name' => 'Grace']);

    Sentinel::transaction('undo-the-rename', static fn (): mixed => $opening->restore());

    expect(auditsOf($record)->last()->transaction_id)->not->toBeNull();
});

it('brings a record back out of the recycle bin', function (): void {
    $record = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $record->delete();

    auditsOf($record)->first()->restore();

    expect(SoftDeletingSubject::query()->find($record->getKey()))->toBeInstanceOf(Model::class);
});

it('is not a transition, even when it moves a declared state column', function (): void {
    $record = TransitioningSubject::query()->create(['status' => 'draft']);
    $opening = auditsOf($record)->first();
    $record->update(['status' => 'paid']);

    $opening->restore();

    expect($record->fresh()->status)->toBe('draft')
        ->and(Sentinel::transitions()->for($record)->get())->toHaveCount(1)
        ->and(auditsOf($record)->last()->audit_type)->toBe(Restorer::AUDIT_TYPE);
});

it('refuses an entry that names no record, without touching anything', function (): void {
    $entry = restorableEntry(new AuditedSubject, ['name' => 'Ada'], [
        'subject_type' => null,
        'subject_id' => null,
    ]);

    expect($entry->restore()->refused)->toBe(Omission::SubjectMissing);
});

it('refuses an entry that portrays no state at all', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(restorableEntry($record, [], ['before' => null])->restore()->refused)
        ->toBe(Omission::EntryStateless);
});
