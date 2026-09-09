<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Attribution;
use ElPandaPe\Sentinel\Support\Reference;

use function ElPandaPe\Sentinel\Tests\auditData;

it('leaves every column alone when it names nobody and no tenant', function (): void {
    $audit = auditData(['actor_type' => 'user', 'actor_id' => '1', 'impersonator_type' => 'user', 'impersonator_id' => '2', 'tenant_id' => 'acme']);

    Attribution::none()->apply($audit);

    expect($audit->actor_type)->toBe('user')
        ->and($audit->actor_id)->toBe('1')
        ->and($audit->impersonator_id)->toBe('2')
        ->and($audit->tenant_id)->toBe('acme');
});

it('names nobody when handed no reference', function (): void {
    $audit = auditData(['actor_type' => 'user', 'actor_id' => '1', 'impersonator_id' => '2']);

    Attribution::by(null)->apply($audit);

    expect($audit->actor_id)->toBe('1')
        ->and($audit->impersonator_id)->toBe('2');
});

it('puts the actor it names on the entry and drops the impersonator with it', function (): void {
    $audit = auditData(['actor_type' => 'user', 'actor_id' => '1', 'impersonator_type' => 'user', 'impersonator_id' => '2']);

    Attribution::by(new Reference('member', '77'))->apply($audit);

    expect($audit->actor_type)->toBe('member')
        ->and($audit->actor_id)->toBe('77')
        ->and($audit->impersonator_type)->toBeNull()
        ->and($audit->impersonator_id)->toBeNull();
});

it('keeps the tenant of the entry when it names none', function (): void {
    $audit = auditData(['tenant_id' => 'acme']);

    Attribution::by(new Reference('member', '77'))->apply($audit);

    expect($audit->tenant_id)->toBe('acme');
});

it('names a tenant over the one the entry carries', function (): void {
    $audit = auditData(['tenant_id' => 'acme']);

    Attribution::none()->onBehalfOf('globex')->apply($audit);

    expect($audit->tenant_id)->toBe('globex');
});

it('names no tenant as a value, not as silence', function (): void {
    $audit = auditData(['tenant_id' => 'acme']);

    Attribution::none()->onBehalfOf(null)->apply($audit);

    expect($audit->tenant_id)->toBeNull();
});

it('keeps the actor it was given when it goes on to name a tenant', function (): void {
    $audit = auditData(['impersonator_type' => 'user', 'impersonator_id' => '2']);

    Attribution::by(new Reference('member', '77'))->onBehalfOf('acme')->apply($audit);

    expect($audit->actor_type)->toBe('member')
        ->and($audit->actor_id)->toBe('77')
        ->and($audit->impersonator_id)->toBeNull()
        ->and($audit->tenant_id)->toBe('acme');
});
