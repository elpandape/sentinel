<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;

beforeEach(function (): void {
    $this->invoice = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
    $this->invoice->update(['name' => 'renamed']);
    $this->invoice->update(['status' => 'pending']);

    Sentinel::event('invoice.received')->subject($this->invoice)->record();
    Sentinel::transition($this->invoice, from: 'pending', to: 'paid')->reason('Cleared')->record();

    $this->invoice->delete();
});

it('chains every kind of entry into the one stream, in the order they happened', function (): void {
    expect(Audit::query()->orderBy('sequence')->pluck('audit_type')->all())
        ->toBe(['model', 'model', 'transition', 'custom', 'transition', 'model']);
});

it('verifies a stream that mixes model changes, stated facts and transitions', function (): void {
    $result = Sentinel::verifyIntegrity('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(6);
});

it('notices a transition whose states were edited under it', function (): void {
    $transition = Audit::query()->where('audit_type', 'transition')->orderBy('sequence')->firstOrFail();

    DB::table(auditsTable())->where('id', $transition->id)->update([
        'changes' => json_encode([['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'approved']], JSON_THROW_ON_ERROR),
    ]);

    $result = Sentinel::verifyIntegrity('global');

    expect($result->isIntact())->toBeFalse()
        ->and($result->reason)->toBe(IntegrityBreak::HashMismatch);
});

it('notices a reason rewritten after the fact', function (): void {
    $transition = Audit::query()->where('audit_type', 'transition')->orderByDesc('sequence')->firstOrFail();

    DB::table(auditsTable())->where('id', $transition->id)->update([
        'metadata' => json_encode(['transition' => ['attribute' => 'status', 'reason' => 'Something else']], JSON_THROW_ON_ERROR),
    ]);

    expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeFalse();
});

it('gives a transition the same version counter every entry about that subject shares', function (): void {
    expect(Audit::query()->orderBy('sequence')->pluck('version')->all())->toBe([1, 2, 3, 4, 5, 6]);
});
