<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\Article;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\Author;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Support\Facades\DB;

it('gives every entry of one operation the same identifier', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
        AuditedSubject::query()->create(['name' => 'payment']);
    });

    $ids = Audit::query()->pluck('transaction_id')->unique();

    expect($ids)->toHaveCount(1)
        ->and($ids->firstOrFail())->not->toBeNull();
});

it('correlates a model change, a pivot sync and a hand-over as one operation', function (): void {
    $team = Team::query()->create(['name' => 'core']);
    Member::query()->create(['name' => 'ada']);
    $author = Author::query()->create(['name' => 'first']);
    $second = Author::query()->create(['name' => 'second']);
    $article = Article::query()->create(['title' => 'one', 'author_id' => $author->getKey()]);

    Audit::query()->delete();

    Sentinel::transaction('editorial-handover', function () use ($team, $article, $second): void {
        $team->update(['name' => 'platform']);
        $team->members()->sync([1]);
        $article->update(['author_id' => $second->getKey()]);
    });

    $entries = Audit::query()->orderBy('id')->get();
    $header = AuditTransaction::query()->firstOrFail();

    expect($entries->pluck('transaction_id')->unique()->all())->toBe([$header->id])
        ->and($entries->pluck('audit_type')->unique()->sort()->values()->all())->toBe(['model', 'relation'])
        ->and($header->audits_count)->toBe($entries->count());
});

it('keeps the outer identifier through three levels of nesting', function (): void {
    Sentinel::transaction('outer', function (): void {
        Sentinel::transaction('middle', function (): void {
            Sentinel::transaction('inner', function (): void {
                AuditedSubject::query()->create(['name' => 'deep']);
            });
        });
    });

    expect(AuditTransaction::query()->count())->toBe(1)
        ->and(Audit::query()->pluck('transaction_id')->unique())->toHaveCount(1);
});

it('keeps the names of the operations it nested rather than losing them', function (): void {
    Sentinel::transaction('outer', function (): void {
        Sentinel::transaction('middle', function (): void {
            Sentinel::transaction('inner', function (): void {});
        });
    });

    $header = AuditTransaction::query()->firstOrFail();

    expect($header->name)->toBe('outer')
        ->and($header->metadata)->toBe(['nested' => ['middle', 'inner']]);
});

it('names a nested operation once, however many times it runs', function (): void {
    Sentinel::transaction('outer', function (): void {
        Sentinel::transaction('recalculate', function (): void {});
        Sentinel::transaction('recalculate', function (): void {});
        Sentinel::transaction('outer', function (): void {});
    });

    expect(AuditTransaction::query()->firstOrFail()->metadata)->toBe(['nested' => ['recalculate']]);
});

it('closes a header for an operation that wrote nothing', function (): void {
    Sentinel::transaction('nothing-happened', function (): void {});

    $header = AuditTransaction::query()->firstOrFail();

    expect($header->audits_count)->toBe(0)
        ->and($header->finished_at)->not->toBeNull()
        ->and($header->metadata)->toBeNull();
});

it('hands back what the operation returned', function (): void {
    $value = Sentinel::transaction('compute', static fn (): string => 'settled');

    expect($value)->toBe('settled');
});

it('closes the header when the operation throws, and keeps what it wrote first', function (): void {
    $failure = fn (): mixed => Sentinel::transaction('half-done', function (): void {
        AuditedSubject::query()->create(['name' => 'before the fall']);

        throw new RuntimeException('nope');
    });

    expect($failure)->toThrow(RuntimeException::class);

    $header = AuditTransaction::query()->firstOrFail();

    expect($header->finished_at)->not->toBeNull()
        ->and($header->audits_count)->toBe(1)
        ->and($header->metadata)->toBe(['failed' => RuntimeException::class])
        ->and(Audit::query()->firstOrFail()->transaction_id)->toBe($header->id);
});

it('records the class of a failure and not its message', function (): void {
    try {
        Sentinel::transaction('leaky', function (): void {
            throw new RuntimeException('card 4111111111111111 declined');
        });
    } catch (RuntimeException) {
        // The assertion is what the header kept, not that it threw.
    }

    expect(json_encode(AuditTransaction::query()->firstOrFail()->metadata))
        ->not->toContain('4111111111111111');
});

it('leaves an entry outside any operation uncorrelated', function (): void {
    AuditedSubject::query()->create(['name' => 'lone']);

    expect(Audit::query()->firstOrFail()->transaction_id)->toBeNull()
        ->and(AuditTransaction::query()->count())->toBe(0);
});

it('stops correlating once the operation has closed', function (): void {
    Sentinel::transaction('done', function (): void {
        AuditedSubject::query()->create(['name' => 'inside']);
    });

    AuditedSubject::query()->create(['name' => 'outside']);

    $entries = Audit::query()->orderBy('id')->get();

    expect($entries->first()->transaction_id)->not->toBeNull()
        ->and($entries->last()->transaction_id)->toBeNull();
});

it('names the actor and the tenant the way an entry does', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);
    config()->set('sentinel.resolvers.tenant.using', fn (): string => 'acme');

    Sentinel::transaction('billed', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
    });

    $header = AuditTransaction::query()->firstOrFail();
    $entry = Audit::query()->firstOrFail();

    expect($header->actor_type)->toBe($entry->actor_type)
        ->and($header->actor_id)->toBe($entry->actor_id)
        ->and($header->tenant_id)->toBe('acme');
});

it('opens no database transaction of its own', function (): void {
    Sentinel::transaction('bare', function (): void {
        expect(DB::transactionLevel())->toBe(0);
    });
});

it('writes no header at all when the package is not recording', function (): void {
    config()->set('sentinel.enabled', false);

    $value = Sentinel::transaction('disabled', static fn (): string => 'ran anyway');

    expect($value)->toBe('ran anyway')
        ->and(AuditTransaction::query()->count())->toBe(0);
});

it('opens the header before the operation runs, so one that dies is still findable', function (): void {
    Sentinel::transaction('long-running', function (): void {
        $header = AuditTransaction::query()->firstOrFail();

        expect($header->name)->toBe('long-running')
            ->and($header->finished_at)->toBeNull();
    });
});
