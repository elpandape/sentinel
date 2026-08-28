<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditQuery;

it('writes no entry for a transaction that rolled back', function (): void {
    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'never happened']);

        throw new RuntimeException('undo');
    });
})->throws(RuntimeException::class);

it('leaves the trail empty after that rollback', function (): void {
    try {
        DB::transaction(function (): void {
            AuditedSubject::query()->create(['name' => 'never happened']);

            throw new RuntimeException('undo');
        });
    } catch (RuntimeException) {
        // The assertion is the empty trail, not the throw.
    }

    expect(Audit::query()->count())->toBe(0);
});

it('writes the entry once the transaction commits', function (): void {
    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'settled']);
    });

    expect(Audit::query()->count())->toBe(1)
        ->and(Audit::query()->firstOrFail()->event)->toBe('created');
});

it('discards a pivot change the same way when its transaction rolls back', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    Member::query()->create(['name' => 'ada']);
    Audit::query()->delete();

    try {
        DB::transaction(function () use ($team): void {
            $team->members()->attach(1);

            throw new RuntimeException('undo');
        });
    } catch (RuntimeException) {
        // The assertion is the empty trail, not the throw.
    }

    expect(Audit::query()->count())->toBe(0);
});

it('keeps the outer level and drops the inner one when a savepoint rolls back', function (): void {
    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'outer']);

        try {
            DB::transaction(function (): void {
                AuditedSubject::query()->create(['name' => 'inner']);

                throw new RuntimeException('undo the inner one');
            });
        } catch (RuntimeException) {
            // The savepoint is what is being tested, not the exception.
        }
    });

    expect(Audit::query()->pluck('event'))->toHaveCount(1)
        ->and(Audit::query()->firstOrFail()->after)->toMatchArray(['name' => 'outer']);
});

it('settles nothing until the outermost transaction commits', function (): void {
    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'waiting']);

        expect(Audit::query()->count())->toBe(0);
    });

    expect(Audit::query()->count())->toBe(1);
});

it('claims what the rollback undid when after_commit is turned off', function (): void {
    config()->set('sentinel.transactions.after_commit', false);

    $ledger = app(MemoryLedger::class);
    app()->instance(Ledger::class, $ledger);

    try {
        DB::transaction(function (): void {
            AuditedSubject::query()->create(['name' => 'claimed anyway']);

            throw new RuntimeException('undo');
        });
    } catch (RuntimeException) {
        // The option's contract is the assertion: a ledger that outlives the rollback.
    }

    expect($ledger->query(auditQuery($ledger)))->toHaveCount(1);
});

it('has the database undo it anyway when the ledger shares the connection that rolled back', function (): void {
    config()->set('sentinel.transactions.after_commit', false);

    try {
        DB::transaction(function (): void {
            AuditedSubject::query()->create(['name' => 'claimed anyway']);

            throw new RuntimeException('undo');
        });
    } catch (RuntimeException) {
        // What after_commit protects against is only visible where the ledger is not in the
        // transaction: a dedicated connection, or a store that is not this database.
    }

    expect(Audit::query()->count())->toBe(0);
});

it('behaves exactly as before outside any transaction', function (): void {
    $audit = AuditedSubject::query()->create(['name' => 'plain'])->latestAudit();

    expect($audit)->not->toBeNull()
        ->and(Audit::query()->count())->toBe(1);
});

it('keeps the manual context a callback pushed, even though it closed before the commit', function (): void {
    DB::transaction(function (): void {
        Sentinel::withContext(['reason' => 'approved by finance'], function (): void {
            AuditedSubject::query()->create(['name' => 'invoice']);
        });
    });

    expect(Audit::query()->firstOrFail()->context)->toHaveKey('reason')
        ->and(Audit::query()->firstOrFail()->context['reason'])->toBe('approved by finance');
});

it('keeps the actor of the capture when someone logs in before the commit', function (): void {
    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'anonymous work']);

        auth()->guard()->setUser(ActingUser::query()->create(['name' => 'Late']));
    });

    expect(Audit::query()->firstOrFail()->actor_id)->toBeNull();
});

it('keeps the tenant of the capture, so the entry lands on the chain it belonged to', function (): void {
    config()->set('sentinel.integrity.enabled', true);
    config()->set('sentinel.integrity.stream', 'tenant');

    $tenant = 'acme';
    config()->set('sentinel.resolvers.tenant.using', function () use (&$tenant): string {
        return $tenant;
    });

    DB::transaction(function () use (&$tenant): void {
        AuditedSubject::query()->create(['name' => 'billed']);

        $tenant = 'globex';
    });

    expect(Audit::query()->firstOrFail()->stream)->toBe('tenant:acme');
});

it('correlates a deferred entry with the operation that captured it', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        DB::transaction(function (): void {
            AuditedSubject::query()->create(['name' => 'invoice']);
        });
    });

    expect(Audit::query()->firstOrFail()->transaction_id)->not->toBeNull();
});

it('announces a deferred write that failed instead of throwing out of the commit', function (): void {
    Event::fake([AuditWriteFailed::class]);
    app()->instance(Ledger::class, new FailingLedger);

    DB::transaction(function (): void {
        AuditedSubject::query()->create(['name' => 'doomed']);
    });

    Event::assertDispatched(
        AuditWriteFailed::class,
        static fn (AuditWriteFailed $event): bool => $event->event === 'created'
            && $event->failure->getMessage() === FailingLedger::REASON,
    );
});

it('still throws outside a transaction, where nothing else depends on it', function (): void {
    app()->instance(Ledger::class, new FailingLedger);

    expect(fn (): mixed => AuditedSubject::query()->create(['name' => 'doomed']))
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('reads the failure back as a line in both languages', function (): void {
    $failed = AuditWriteFailed::of(
        new ElPandaPe\Sentinel\Data\AuditData(
            'model',
            'created',
            ElPandaPe\Sentinel\Enums\Severity::Info,
            new DateTimeImmutable('2026-08-28 10:00:00'),
            subject_type: 'invoice',
            subject_id: '7',
        ),
        new RuntimeException('the ledger is down'),
    );

    expect($failed->message())->toContain('the ledger is down')
        ->and($failed->transactionId)->toBeNull();
});
