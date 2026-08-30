<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Integrity\StreamVerification;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\signingWith;
use function ElPandaPe\Sentinel\Tests\verifier;

it('names every stream it holds, in an order two runs can be diffed on', function (): void {
    seedTheReferenceChain();

    expect(ledger()->streams())->toBe([ReferenceChain::STREAM, ReferenceChain::FORK]);
});

it('walks every chain the ledger holds', function (): void {
    seedTheReferenceChain();

    $report = Sentinel::verifyEverything();

    expect($report->isIntact())->toBeTrue()
        ->and($report->checked())->toBe(10)
        ->and($report->streams)->toHaveCount(2)
        ->and($report->firstBreak())->toBeNull();
});

it('reports the whole trail as broken when one stream in it is', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $report = Sentinel::verifyEverything();

    expect($report->isIntact())->toBeFalse()
        ->and($report->firstBreak())->toBeInstanceOf(StreamVerification::class)
        ->and($report->firstBreak()?->stream())->toBe(ReferenceChain::STREAM)
        ->and($report->firstBreak()?->break()?->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($report->firstBreak()?->break()?->sequence)->toBe(6);
});

it('refuses to answer for a ledger that cannot say which chains it holds', function (): void {
    expect(fn (): mixed => verifier(app(NullLedger::class))->verifyEverything())
        ->toThrow(QueryException::class, 'cannot say which streams it holds');
});

it('counts the signatures it found alongside the links it checked', function (): void {
    seedTheReferenceChain();

    $report = Sentinel::verifyEverything();

    expect($report->signatures())->toBe([SignatureState::Unsigned->value => 10]);
});

it('separates what is signed from what came before the signing did', function (): void {
    seedTheReferenceChain();

    signingWith('v1', SigningKeys::SECRET);

    ledger()->write(auditData(['stream' => ReferenceChain::STREAM]));

    $report = Sentinel::verifyEverything();

    expect($report->isIntact())->toBeTrue()
        ->and($report->signatures())->toBe([
            SignatureState::Unsigned->value => 10,
            SignatureState::Signed->value => 1,
        ]);
});

it('reports a forged signature without calling the chain broken', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData(['stream' => ReferenceChain::STREAM]));

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature' => str_repeat('0', 64)]);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->chain->isIntact())->toBeTrue()
        ->and($verification->isIntact())->toBeFalse()
        ->and($verification->break()?->reason)->toBe(IntegrityBreak::SignatureMismatch)
        ->and($verification->break()?->auditId)->toBe($audit->id)
        ->and($verification->signatures)->toBe([SignatureState::Invalid->value => 1]);
});

it('announces a forged signature the way it announces a broken link', function (): void {
    Event::fake([IntegrityVerificationFailed::class]);

    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData(['stream' => ReferenceChain::STREAM]));

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature' => str_repeat('0', 64)]);

    verifier()->verify(ReferenceChain::STREAM);

    Event::assertDispatched(
        IntegrityVerificationFailed::class,
        static fn (IntegrityVerificationFailed $event): bool => $event->reason === IntegrityBreak::SignatureMismatch
            && $event->auditId === $audit->id
            && str_contains($event->message(), 'does not verify'),
    );
});

it('keeps walking past a signature it could not verify', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $written = ledger()->writeMany([
        auditData(['stream' => ReferenceChain::STREAM]),
        auditData(['stream' => ReferenceChain::STREAM]),
        auditData(['stream' => ReferenceChain::STREAM]),
    ]);

    DB::table(auditsTable())->where('id', $written[0]->id)->update(['signature' => str_repeat('0', 64)]);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->chain->checked)->toBe(3)
        ->and($verification->signatures)->toBe([
            SignatureState::Invalid->value => 1,
            SignatureState::Signed->value => 2,
        ]);
});

it('stops at a broken link and says nothing about the signatures past it', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->signatures)->toBe([SignatureState::Unsigned->value => 5]);
});
