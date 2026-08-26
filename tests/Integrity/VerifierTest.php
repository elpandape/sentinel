<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    app(Ledger::class)->writeMany([auditData(), auditData(), auditData()]);
});

it('confirms an untouched chain and says how much it looked at', function (): void {
    $result = verifier()->verifyStream('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(3)
        ->and($result->stream)->toBe('global')
        ->and($result->reason)->toBeNull()
        ->and($result->sequence)->toBeNull()
        ->and($result->auditId)->toBeNull();
});

it('confirms a chain of a single entry', function (): void {
    app(Ledger::class)->write(auditData(['stream' => 'beta']));

    expect(verifier()->verifyStream('beta')->checked)->toBe(1)
        ->and(verifier()->verifyStream('beta')->isIntact())->toBeTrue();
});

it('confirms an empty stream without pretending it checked anything', function (): void {
    $result = verifier()->verifyStream('nonesuch');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(0);
});

it('catches a row whose content no longer matches its own hash', function (): void {
    DB::table(auditsTable())->where('sequence', 2)->update(['event' => 'tampered']);

    $result = verifier()->verifyStream('global');

    expect($result->isIntact())->toBeFalse()
        ->and($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(2)
        ->and($result->checked)->toBe(1);
});

it('catches a row whose link no longer points at the entry before it', function (): void {
    DB::table(auditsTable())->where('sequence', 3)->update(['previous_hash' => str_repeat('f', 64)]);
    $audit = Audit::query()->where('sequence', 3)->firstOrFail();
    DB::table(auditsTable())->where('sequence', 3)->update(['hash' => hasher()->hash($audit)]);

    $result = verifier()->verifyStream('global');

    expect($result->reason)->toBe(IntegrityBreak::LinkMismatch)
        ->and($result->sequence)->toBe(3);
});

it('catches an entry that claims to open a chain it does not open', function (): void {
    DB::table(auditsTable())->where('sequence', 1)->update(['previous_hash' => str_repeat('a', 64)]);
    $audit = Audit::query()->where('sequence', 1)->firstOrFail();
    DB::table(auditsTable())->where('sequence', 1)->update(['hash' => hasher()->hash($audit)]);

    expect(verifier()->verifyStream('global')->reason)->toBe(IntegrityBreak::LinkMismatch);
});

it('catches a hole in the sequence', function (): void {
    DB::table(auditsTable())->where('sequence', 2)->delete();

    $result = verifier()->verifyStream('global');

    expect($result->reason)->toBe(IntegrityBreak::SequenceGap)
        ->and($result->sequence)->toBe(2);
});

it('catches an order that was rearranged even when every row is coherent on its own', function (): void {
    $swapped = Audit::query()->where('sequence', 2)->firstOrFail();
    DB::table(auditsTable())->where('sequence', 2)->delete();
    DB::table(auditsTable())->where('sequence', 3)->update(['sequence' => 2]);

    $rearranged = Audit::query()->where('sequence', 2)->firstOrFail();
    DB::table(auditsTable())->where('sequence', 2)->update(['hash' => hasher()->hash($rearranged)]);

    expect($swapped->sequence)->toBe(2)
        ->and(verifier()->verifyStream('global')->reason)->toBe(IntegrityBreak::LinkMismatch);
});

it('verifies a range without walking what falls outside it', function (): void {
    DB::table(auditsTable())->where('sequence', 1)->update(['event' => 'tampered']);

    expect(verifier()->verifyStream('global', 2, 3)->isIntact())->toBeTrue()
        ->and(verifier()->verifyStream('global', 1, 1)->isIntact())->toBeFalse();
});

it('checks only the range it was given', function (): void {
    expect(verifier()->verifyStream('global', 2)->checked)->toBe(2)
        ->and(verifier()->verifyStream('global', 1, 2)->checked)->toBe(2);
});

it('announces the break as an event, with the point it broke at', function (): void {
    Event::fake();
    DB::table(auditsTable())->where('sequence', 2)->update(['event' => 'tampered']);

    verifier()->verifyStream('global');

    Event::assertDispatched(IntegrityVerificationFailed::class, fn (IntegrityVerificationFailed $event): bool => $event->stream === 'global'
        && $event->sequence === 2
        && $event->reason === IntegrityBreak::HashMismatch
        && str_contains($event->message(), 'sequence 2'));
});

it('says nothing when the chain holds', function (): void {
    Event::fake();

    verifier()->verifyStream('global');

    Event::assertNotDispatched(IntegrityVerificationFailed::class);
});

it('speaks the language the application is set to', function (): void {
    $event = new IntegrityVerificationFailed('global', IntegrityBreak::SequenceGap, 2, '01JXXXXXXXXXXXXXXXXXXXXXXX');

    app()->setLocale('es');

    expect($event->message())->toContain('secuencia 2');
});

it('verifies one entry on its own', function (): void {
    expect(Audit::query()->where('sequence', 1)->firstOrFail()->verifyIntegrity())->toBeTrue();

    DB::table(auditsTable())->where('sequence', 1)->update(['event' => 'tampered']);

    expect(Audit::query()->where('sequence', 1)->firstOrFail()->verifyIntegrity())->toBeFalse();
});

it('declares no exception by the name of the event', function (): void {
    expect(class_exists('ElPandaPe\Sentinel\Exceptions\IntegrityVerificationFailed'))->toBeFalse()
        ->and(is_a(IntegrityVerificationFailed::class, Throwable::class, true))->toBeFalse();
});
