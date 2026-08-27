<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Support\Reference;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\Money;

it('reads the morph type and the key off a model', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    $reference = Reference::to($subject);

    expect($reference->type)->toBe(AuditedSubject::class)
        ->and($reference->id)->toBe((string) $subject->getKey());
});

it('takes the recorded type and key when there is no model left to hand over', function (): void {
    $reference = Reference::to(AuditedSubject::class, 7);

    expect($reference->type)->toBe(AuditedSubject::class)
        ->and($reference->id)->toBe('7');
});

it('refuses a model that has no key yet', function (): void {
    expect(fn (): Reference => Reference::to(new AuditedSubject))
        ->toThrow(QueryException::class, 'no key yet');
});

it('refuses a recorded type with no key beside it', function (): void {
    expect(fn (): Reference => Reference::to(AuditedSubject::class))
        ->toThrow(QueryException::class, 'as the second argument');
});

it('refuses an object that is not a model', function (): void {
    expect(fn (): Reference => Reference::to(new Money(1, 'EUR')))
        ->toThrow(QueryException::class, Money::class);
});
