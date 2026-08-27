<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Support\Facades\Route;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\httpRequest;

it('writes the circumstances the entry was captured in', function (): void {
    httpRequest('/api/invoices', ['User-Agent' => 'Sentinel/1.0']);

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $audit = auditsOf($subject)->first();

    expect($audit?->source)->toBe(Source::Api)
        ->and($audit?->request_id)->toBeString()
        ->and($audit?->context['user_agent'])->toBe('Sentinel/1.0')
        ->and($audit?->context['method'])->toBe('GET')
        ->and($audit?->context)->toHaveKeys(['hostname', 'environment', 'ip', 'url']);
});

it('names who acted on an entry written behind a route', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Route::get('/invoices', function (): string {
        AuditedSubject::query()->create(['name' => 'Invoice']);

        return 'ok';
    })->name('invoices.store');

    $this->actingAs($user)->get('/invoices')->assertOk();

    $audit = auditsOf(AuditedSubject::query()->firstOrFail())->first();

    expect($audit?->source)->toBe(Source::Http)
        ->and($audit?->actor_type)->toBe($user->getMorphClass())
        ->and($audit?->actor_id)->toBe((string) $user->getKey())
        ->and($audit?->impersonator_type)->toBeNull()
        ->and($audit?->impersonator_id)->toBeNull()
        ->and($audit?->context['route'])->toBe('invoices.store');
});

it('carries the manual context alongside the resolved one', function (): void {
    $subject = Sentinel::withContext(
        ['reason' => 'Approved by finance'],
        fn (): AuditedSubject => AuditedSubject::query()->create(['name' => 'Ada']),
    );

    expect(auditsOf($subject)->first()?->context['reason'])->toBe('Approved by finance');
});

it('keeps verifying the entry it just wrote', function (): void {
    httpRequest('/invoices');

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $audit = auditsOf($subject)->first();

    expect($audit?->verifyIntegrity())->toBeTrue()
        ->and($audit?->payload_version)->toBe(1);
});

it('correlates every entry of one request under one identifier', function (): void {
    httpRequest('/invoices');

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Ada Lovelace']);

    $identifiers = auditsOf($subject)->pluck('request_id')->unique();

    expect($identifiers)->toHaveCount(1)
        ->and($identifiers->first())->toBeString();
});

it('opens a chain of its own once a tenant resolves', function (): void {
    config()->set('sentinel.resolvers.tenant.using', fn (): string => 'acme');

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $audit = auditsOf($subject)->first();

    expect($audit?->tenant_id)->toBe('acme')
        ->and($audit?->stream)->toBe('tenant:acme')
        ->and($audit?->sequence)->toBe(1)
        ->and($audit?->previous_hash)->toBeNull();
});
