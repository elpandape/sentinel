<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\PromotingResolver;
use ElPandaPe\Sentinel\Tests\Fixtures\SubstituteResolver;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\contextEngine;
use function ElPandaPe\Sentinel\Tests\httpRequest;

it('sends a promoted key to its column and every other key to the payload', function (): void {
    config()->set('sentinel.resolvers.tenant.class', PromotingResolver::class);

    $audit = contextEngine()(auditData());

    expect($audit->tenant_id)->toBe('acme')
        ->and($audit->context['district'])->toBe('north')
        ->and($audit->context)->not->toHaveKey('tenant_id');
});

it('decides the source instead of leaving the default standing', function (): void {
    httpRequest('/api/invoices');

    expect(contextEngine()(auditData())->source)->toBe(Source::Api);
});

it('leaves the columns no resolver filled as null', function (): void {
    $audit = contextEngine()(auditData());

    expect($audit->actor_type)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->impersonator_type)->toBeNull()
        ->and($audit->impersonator_id)->toBeNull()
        ->and($audit->tenant_id)->toBeNull()
        ->and($audit->trace_id)->toBeNull()
        ->and($audit->span_id)->toBeNull();
});

it('produces the same entry when it runs twice over it', function (): void {
    config()->set('sentinel.resolvers.tenant.class', PromotingResolver::class);
    httpRequest('/invoices');

    $engine = contextEngine();
    $once = $engine(auditData());
    $twice = $engine($once);

    expect($twice->tenant_id)->toBe($once->tenant_id)
        ->and($twice->context)->toBe($once->context)
        ->and($twice->source)->toBe($once->source)
        ->and($twice->request_id)->toBe($once->request_id);
});

it('clears a column when the signal behind it is gone', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);

    $engine = contextEngine();
    $audit = $engine(auditData());

    expect($audit->actor_id)->toBe((string) $user->getKey());

    auth()->guard()->forgetUser();

    expect($engine($audit)->actor_id)->toBeNull();
});

it('merges the manual context over the resolved one', function (): void {
    config()->set('sentinel.resolvers.host.class', SubstituteResolver::class);

    $audit = Sentinel::withContext(
        ['substituted' => 'by hand', 'reason' => 'Approved by finance'],
        fn (): object => contextEngine()(auditData()),
    );

    expect($audit->context['substituted'])->toBe('by hand')
        ->and($audit->context['reason'])->toBe('Approved by finance');
});

it('refuses to let the manual context write a promoted column', function (): void {
    $audit = Sentinel::withContext(
        ['tenant_id' => 'forged', 'actor_id' => '999', 'source' => 'http'],
        fn (): object => contextEngine()(auditData()),
    );

    expect($audit->tenant_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->source)->toBe(Source::System)
        ->and($audit->context['tenant_id'])->toBe('forged');
});

it('resolves the host once and the tenant on every capture', function (): void {
    $engine = contextEngine();
    $before = $engine(auditData())->context['hostname'];

    config()->set('sentinel.resolvers.host.class', SubstituteResolver::class);
    config()->set('sentinel.resolvers.tenant.using', fn (): string => 'globex');

    $after = $engine(auditData());

    expect($after->context['hostname'])->toBe($before)
        ->and($after->context)->not->toHaveKey('substituted')
        ->and($after->tenant_id)->toBe('globex');
});
