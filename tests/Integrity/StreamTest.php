<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditableSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\StaticStreamResolver;
use Illuminate\Database\Eloquent\Relations\Relation;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\stream;

// enforceMorphMap is global static state and the suite runs in one process under coverage.
afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('names the single chain when the scope is global', function (): void {
    expect(stream(['integrity.stream' => 'global'])->resolve(auditData(['tenant_id' => 'acme'])))
        ->toBe('global');
});

it('prefixes the tenant so it cannot collide with a type', function (): void {
    expect(stream(['integrity.stream' => 'tenant'])->resolve(auditData(['tenant_id' => 'acme'])))
        ->toBe('tenant:acme');
});

it('falls back to the global chain when the entry carries no tenant', function (): void {
    expect(stream(['integrity.stream' => 'tenant'])->resolve(auditData()))->toBe('global');
});

it('prefixes the subject type and prefers its morph alias', function (): void {
    Relation::enforceMorphMap(['subject' => AuditableSubject::class]);

    expect(stream(['integrity.stream' => 'subject_type'])
        ->resolve(auditData(['subject_type' => AuditableSubject::class])))
        ->toBe('type:subject');
});

it('falls back to the global chain when the entry carries no subject', function (): void {
    expect(stream(['integrity.stream' => 'subject_type'])->resolve(auditData()))->toBe('global');
});

it('refuses a class that exists but answers to no resolver contract', function (): void {
    stream(['integrity.stream' => AuditableSubject::class])->resolve(auditData());
})->throws(ConfigurationException::class, 'integrity.stream');

it('lets a closure name the chain', function (): void {
    $strategy = static fn (): string => 'closure';

    expect(stream(['integrity.stream' => $strategy])->resolve(auditData()))->toBe('closure');
});

it('rejects a closure that does not return a string', function (): void {
    $strategy = static fn (): int => 1;

    expect(fn (): string => stream(['integrity.stream' => $strategy])->resolve(auditData()))
        ->toThrow(ConfigurationException::class, 'integrity.stream');
});

it('lets a resolver class name the chain, which is the cacheable form', function (): void {
    expect(stream(['integrity.stream' => StaticStreamResolver::class])->resolve(auditData()))
        ->toBe('resolver:model');
});

it('rejects a strategy that is neither a mode, a closure nor a resolver', function (): void {
    expect(fn (): string => stream(['integrity.stream' => 'nonesuch'])->resolve(auditData()))
        ->toThrow(ConfigurationException::class, 'integrity.stream');
});

it('rejects a strategy that is not a string or a closure at all', function (): void {
    expect(fn (): string => stream(['integrity.stream' => 42])->resolve(auditData()))
        ->toThrow(ConfigurationException::class, 'integrity.stream');
});

it('lets the entry carry its own stream', function (): void {
    expect(stream()->resolve(auditData(['stream' => 'explicit'])))->toBe('explicit');
});

it('accepts a name that fills the column to the last character', function (): void {
    expect(stream()->resolve(auditData(['stream' => str_repeat('a', 64)])))->toBe(str_repeat('a', 64));
});

it('never truncates a name that does not fit the column', function (): void {
    expect(fn (): string => stream()->resolve(auditData(['stream' => str_repeat('a', 65)])))
        ->toThrow(ConfigurationException::class, '64');
});

it('refuses an empty name', function (): void {
    expect(fn (): string => stream()->resolve(auditData(['stream' => ''])))
        ->toThrow(ConfigurationException::class, 'empty');
});
