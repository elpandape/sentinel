<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Support\AuditCollection;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\presenter;

it('reads an entry as who did what to what', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'updated',
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '100',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->entry($audit))->toBe('User #100 changed Invoice #500');
});

it('reads an impersonated entry as who acted on whose behalf', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'updated',
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '100',
        'impersonator_type' => 'App\\Models\\Administrator',
        'impersonator_id' => '1',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->entry($audit))->toBe('Administrator #1 acting as User #100 changed Invoice #500');
});

it('leaves no hole in the sentence when nobody was impersonated', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'updated',
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '100',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->entry($audit))
        ->not->toContain('acting as')
        ->and(presenter()->entry($audit))->not->toContain('  ');
});

it('reads the same entry in the language that is asked for', function (): void {
    app()->setLocale('es');

    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'updated',
        'actor_type' => 'App\\Models\\User',
        'actor_id' => '100',
        'impersonator_type' => 'App\\Models\\Administrator',
        'impersonator_id' => '1',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->entry($audit))->toBe('Administrator #1 en nombre de User #100 cambió Invoice #500');
});

it('reads an entry with no actor without naming one', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData([
        'event' => 'created',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->entry($audit))->toBe('Someone created Invoice #500');
});

it('reads an event it has no word for as the event itself', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData(['event' => 'approved']));

    expect(presenter()->entry($audit))->toContain('approved');
});

it('reads the history of a field as the values it took', function (): void {
    $ledger = app(DatabaseLedger::class);

    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
        $ledger->write(auditData([
            'subject_type' => 'App\\Models\\User',
            'subject_id' => '1',
            'changes' => [['path' => '/email', 'op' => 'replace', 'old' => null, 'new' => $email]],
        ]));
    }

    $history = Sentinel::audits()->for('App\\Models\\User', 1)->whereFieldChanged('email')->get();

    expect(presenter()->fieldHistory($history, 'email'))->toBe(
        "v1  a@example.com\nv2  b@example.com\nv3  c@example.com",
    );
});

it('keeps the version a subject really reached when a field skipped some', function (): void {
    $ledger = app(DatabaseLedger::class);

    foreach ([['/email', 'a@example.com'], ['/name', 'Ada'], ['/email', 'b@example.com']] as [$path, $value]) {
        $ledger->write(auditData([
            'subject_type' => 'App\\Models\\User',
            'subject_id' => '1',
            'changes' => [['path' => $path, 'op' => 'replace', 'old' => null, 'new' => $value]],
        ]));
    }

    $history = Sentinel::audits()->for('App\\Models\\User', 1)->whereFieldChanged('email')->get();

    expect(presenter()->fieldHistory($history, 'email'))->toBe("v1  a@example.com\nv3  b@example.com");
});

it('says what a value that is not a word is', function (mixed $value, string $rendered): void {
    app(DatabaseLedger::class)->write(auditData([
        'subject_type' => 'App\\Models\\User',
        'subject_id' => '1',
        'changes' => [['path' => '/flag', 'op' => 'replace', 'old' => null, 'new' => $value]],
    ]));

    $history = Sentinel::audits()->for('App\\Models\\User', 1)->whereFieldChanged('flag')->get();

    expect(presenter()->fieldHistory($history, 'flag'))->toBe("v1  {$rendered}");
})->with([
    'true' => [true, 'yes'],
    'false' => [false, 'no'],
    'null' => [null, 'nothing'],
    'a list' => [['a', 'b'], 'a structure'],
    'a number' => [42, '42'],
]);

it('reads a timeline as one line per entry, stamped with when it happened', function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData([
        'event' => 'created',
        'occurred_at' => new DateTimeImmutable('2026-08-26 10:02:00'),
        'subject_type' => 'App\\Models\\Role',
        'subject_id' => '3',
    ]));
    $ledger->write(auditData([
        'event' => 'updated',
        'occurred_at' => new DateTimeImmutable('2026-08-26 11:30:00'),
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => '500',
    ]));

    expect(presenter()->timeline(Sentinel::timeline()->get()))->toBe(
        "10:02  Someone created Role #3\n11:30  Someone changed Invoice #500",
    );
});

it('reads an empty trail as nothing at all', function (): void {
    expect(presenter()->timeline(new AuditCollection))->toBeEmpty()
        ->and(presenter()->fieldHistory(new AuditCollection, 'email'))->toBeEmpty();
});

it('is resolved from the container', function (): void {
    expect(app(AuditPresenter::class))->toBeInstanceOf(AuditPresenter::class);
});
