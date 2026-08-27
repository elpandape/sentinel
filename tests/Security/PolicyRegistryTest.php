<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\PolicyRegistry;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('reads what a model declared from its subject type alone', function (): void {
    $policy = new PolicyRegistry()->for(ProtectedSubject::class);

    expect($policy->redacted)->toBe(['email'])
        ->and($policy->hashed)->toBe(['secret'])
        ->and($policy->encrypted)->toBeEmpty();
});

it('follows the morph alias to the model behind it', function (): void {
    Relation::morphMap(['protected' => ProtectedSubject::class]);

    expect(new PolicyRegistry()->for('protected')->redacted)->toBe(['email']);
});

it('declares nothing for a subject type that is not a model', function (): void {
    expect(new PolicyRegistry()->for('orders.imported')->redacted)->toBeEmpty();
});

it('declares nothing for a class that is not a model', function (): void {
    expect(new PolicyRegistry()->for(stdClass::class)->redacted)->toBeEmpty();
});

it('declares nothing for an entry with no subject', function (): void {
    expect(new PolicyRegistry()->for(null)->redacted)->toBeEmpty();
});

it('declares nothing for a model that answers nothing', function (): void {
    expect(new PolicyRegistry()->for(AuditedSubject::class)->redacted)->toBeEmpty();
});

it('reads a subject type once, however many entries it writes', function (): void {
    $registry = new PolicyRegistry;

    expect($registry->for(ProtectedSubject::class))->toBe($registry->for(ProtectedSubject::class));
});
