<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ImmutableAuditException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\CustomAudit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('refuses to save a change over an entry that was already written', function (): void {
    insertAudit(['event' => 'created']);
    $audit = Audit::query()->firstOrFail();
    $audit->event = 'updated';

    expect(fn (): bool => $audit->save())->toThrow(ImmutableAuditException::class, $audit->id);
});

it('leaves the row untouched when a save is refused', function (): void {
    insertAudit(['event' => 'created']);
    $audit = Audit::query()->firstOrFail();
    $audit->event = 'updated';

    rescue(fn (): bool => $audit->save(), report: false);

    expect(DB::table(auditsTable())->value('event'))->toBe('created');
});

it('refuses an update that arrives through fill', function (): void {
    insertAudit();
    $audit = Audit::query()->firstOrFail();

    expect(fn (): bool => $audit->fill(['severity' => 'warning'])->save())
        ->toThrow(ImmutableAuditException::class);
});

it('refuses the update helper of the model', function (): void {
    insertAudit();

    expect(fn (): bool => Audit::query()->firstOrFail()->update(['event' => 'updated']))
        ->toThrow(ImmutableAuditException::class);
});

it('refuses to delete an entry', function (): void {
    insertAudit();

    expect(fn (): ?bool => Audit::query()->firstOrFail()->delete())
        ->toThrow(ImmutableAuditException::class);
});

it('refuses destroy as well, and keeps the row', function (): void {
    insertAudit();
    $id = Audit::query()->firstOrFail()->id;

    expect(fn (): int => Audit::destroy($id))->toThrow(ImmutableAuditException::class)
        ->and(DB::table(auditsTable())->count())->toBe(1);
});

it('guards the configured subclass too', function (): void {
    insertAudit();
    $audit = CustomAudit::query()->firstOrFail();
    $audit->event = 'updated';

    expect(fn (): bool => $audit->save())->toThrow(ImmutableAuditException::class);
});

it('still lets a new entry be written', function (): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    expect($audit->exists)->toBeTrue();
});

it('names the entry it refused to touch', function (): void {
    insertAudit();
    $audit = Audit::query()->firstOrFail();

    expect(fn (): ?bool => $audit->delete())
        ->toThrow(ImmutableAuditException::class, $audit->id);
});
