<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('reads the trail in the order things happened, not the order they were recorded', function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData(['event' => 'last', 'occurred_at' => new DateTimeImmutable('2026-08-26 12:00:00')]));
    $ledger->write(auditData(['event' => 'first', 'occurred_at' => new DateTimeImmutable('2026-08-26 10:00:00')]));
    $ledger->write(auditData(['event' => 'middle', 'occurred_at' => new DateTimeImmutable('2026-08-26 11:00:00')]));

    expect(Sentinel::timeline()->get()->pluck('event')->all())->toBe(['first', 'middle', 'last'])
        ->and(Sentinel::audits()->get()->pluck('event')->all())->toBe(['last', 'first', 'middle']);
});

it('breaks a tie on the identifier, which sorts by the instant it was minted', function (): void {
    foreach ([frozenUlid('AAA1'), frozenUlid('AAA2')] as $index => $id) {
        insertAudit([
            'id' => $id,
            'sequence' => 10 + $index,
            'event' => "at-{$index}",
            'occurred_at' => '2026-08-26 10:00:00.000000',
        ]);
    }

    expect(Sentinel::timeline()->get()->pluck('event')->all())->toBe(['at-0', 'at-1']);
});

it('mixes every source into one line', function (): void {
    $ledger = app(DatabaseLedger::class);

    foreach ([Source::Http, Source::Cli, Source::Queue, Source::Job, Source::Scheduler, Source::System] as $index => $source) {
        $ledger->write(auditData([
            'event' => $source->value,
            'source' => $source,
            'occurred_at' => new DateTimeImmutable("2026-08-26 10:0{$index}:00"),
        ]));
    }

    expect(Sentinel::timeline()->get())->toHaveCount(6)
        ->and(Sentinel::timeline()->whereSource(Source::Queue)->get()->pluck('event')->all())->toBe(['queue']);
});

it('takes the same filters the trail takes', function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData(['event' => 'mine', 'subject_type' => AuditedSubject::class, 'subject_id' => '1', 'tags' => ['billing']]));
    $ledger->write(auditData(['event' => 'theirs', 'subject_type' => AuditedSubject::class, 'subject_id' => '2']));

    expect(Sentinel::timeline()->for(AuditedSubject::class, 1)->get()->pluck('event')->all())->toBe(['mine'])
        ->and(Sentinel::timeline()->whereTag('billing')->get()->pluck('event')->all())->toBe(['mine']);
});

it('hands back every entry with its labels already loaded', function (): void {
    app(DatabaseLedger::class)->write(auditData(['tags' => ['billing']]));

    $entry = Sentinel::timeline()->get()->firstOrFail();

    expect($entry->relationLoaded('tags'))->toBeTrue()
        ->and($entry->tags->pluck('tag')->all())->toBe(['billing']);
});

it('renders a page of mixed subjects without a query per line', function (): void {
    $ledger = app(DatabaseLedger::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Sentinel::withoutAuditing(function (): void {
        for ($index = 0; $index < 10; $index++) {
            AuditedSubject::query()->create(['name' => "subject-{$index}"]);
        }
    });

    foreach (AuditedSubject::query()->get() as $subject) {
        $ledger->write(auditData([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'actor_type' => $user->getMorphClass(),
            'actor_id' => (string) $user->getKey(),
            'tags' => ['billing'],
        ]));
    }

    $entries = Sentinel::timeline()->get();

    DB::enableQueryLog();
    $entries->loadReferences();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($entries)->toHaveCount(10)
        ->and($queries)->toBeLessThanOrEqual(3)
        ->and($entries->firstOrFail()->subject)->not->toBeNull()
        ->and($entries->firstOrFail()->actor)->not->toBeNull();
});

it('answers when a recorded type names no class at all', function (): void {
    insertAudit([
        'id' => frozenUlid('GHOST'),
        'sequence' => 1,
        'subject_type' => 'invoice',
        'subject_id' => '500',
        'actor_type' => 'user',
        'actor_id' => '1',
    ]);

    $entries = Sentinel::timeline()->get()->loadReferences();

    expect($entries)->toHaveCount(1)
        ->and($entries->firstOrFail()->relationLoaded('subject'))->toBeFalse()
        ->and($entries->firstOrFail()->relationLoaded('tags'))->toBeTrue();
});

it('resolves the subjects it can and leaves alone the ones it cannot', function (): void {
    $subject = Sentinel::withoutAuditing(static fn (): AuditedSubject => AuditedSubject::query()->create(['name' => 'real']));

    app(DatabaseLedger::class)->write(auditData([
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(),
    ]));
    insertAudit(['id' => frozenUlid('GHOST2'), 'sequence' => 50, 'subject_type' => 'invoice', 'subject_id' => '9']);

    $entries = Sentinel::timeline()->get()->loadReferences();

    expect($entries->where('subject_type', 'invoice')->firstOrFail()->relationLoaded('subject'))->toBeFalse()
        ->and($entries->where('subject_type', AuditedSubject::class)->firstOrFail()->subject)->not->toBeNull();
});

it('gives find the labels of the entry it found', function (): void {
    $written = app(DatabaseLedger::class)->write(auditData(['tags' => ['billing']]));

    $found = app(DatabaseLedger::class)->find($written->id);

    expect($found?->relationLoaded('tags'))->toBeTrue()
        ->and($found?->tags->pluck('tag')->all())->toBe(['billing']);
});

it('is the trail with one clock in front, and nothing else', function (): void {
    expect(Sentinel::timeline())->toBeInstanceOf(AuditQuery::class)
        ->and(Sentinel::timeline()->byOccurrence)->toBeTrue()
        ->and(Sentinel::audits()->byOccurrence)->toBeFalse();
});
