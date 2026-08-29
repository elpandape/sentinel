<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Restore\Restorer;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Database\Eloquent\Relations\Relation;

use function ElPandaPe\Sentinel\Tests\seedTheFrozenTrail;

beforeEach(function (): void {
    Relation::morphMap(['subject' => AuditedSubject::class]);

    seedTheFrozenTrail();

    Sentinel::withoutAuditing(static fn (): mixed => AuditedSubject::query()->create([
        'name' => 'Ada',
        'active' => true,
        'published_at' => '2026-08-26 10:00:00.123456',
    ]));
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('restores from an entry sealed before the engine that restores it existed', function (): void {
    /** @var Audit $frozen */
    $frozen = Audit::query()->findOrFail('01JGOLDEN000000000000000D4');

    $result = $frozen->restore();

    $record = AuditedSubject::query()->findOrFail(1);

    expect($frozen->payload_version)->toBe(1)
        ->and($result->applied)->toBe(['active', 'name', 'published_at'])
        ->and($record->getAttribute('name'))->toBe('Grace')
        ->and($record->getAttribute('published_at'))->toBeNull()
        ->and($record->getAttribute('active'))->toBeFalsy();
});

it('chains the restoration onto the frozen trail without touching what was there', function (): void {
    /** @var Audit $frozen */
    $frozen = Audit::query()->findOrFail('01JGOLDEN000000000000000D4');

    $frozen->restore();

    /** @var Audit $written */
    $written = Audit::query()->where('audit_type', Restorer::AUDIT_TYPE)->firstOrFail();

    expect($written->source_audit_id)->toBe($frozen->id)
        ->and($written->payload_version)->toBe(1)
        ->and($written->verifyIntegrity())->toBeTrue()
        ->and($frozen->fresh()?->verifyIntegrity())->toBeTrue()
        ->and(Sentinel::verifyIntegrity('global', $written->sequence - 1)->isIntact())->toBeTrue();
});
