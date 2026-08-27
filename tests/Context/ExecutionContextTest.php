<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\ExecutionContext;

beforeEach(function (): void {
    $this->context = new ExecutionContext;
});

it('stores and reads values', function (): void {
    $this->context->set('reason', 'Approved by finance');

    expect($this->context->get('reason'))->toBe('Approved by finance')
        ->and($this->context->has('reason'))->toBeTrue()
        ->and($this->context->all())->toBe(['reason' => 'Approved by finance']);
});

it('returns the default for an unknown key', function (): void {
    expect($this->context->get('missing'))->toBeNull()
        ->and($this->context->get('missing', 'fallback'))->toBe('fallback')
        ->and($this->context->has('missing'))->toBeFalse();
});

it('merges and forgets', function (): void {
    $this->context->set('reason', 'migration');
    $this->context->merge(['a' => 1, 'b' => 2]);
    $this->context->forget('a');

    expect($this->context->all())->toBe(['reason' => 'migration', 'b' => 2]);
});

it('lets what is merged win over what was already there', function (): void {
    $this->context->set('reason', 'migration');
    $this->context->merge(['reason' => 'approved by finance']);

    expect($this->context->all())->toBe(['reason' => 'approved by finance']);
});

it('flushes everything', function (): void {
    $this->context->merge(['a' => 1]);
    $this->context->flush();

    expect($this->context->all())->toBeEmpty();
});

it('restores the previous state after a scope', function (): void {
    $this->context->set('tenant', 'acme');

    $result = $this->context->scope(['tenant' => 'globex', 'reason' => 'migration'], function (): string {
        expect($this->context->get('tenant'))->toBe('globex')
            ->and($this->context->get('reason'))->toBe('migration');

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($this->context->all())->toBe(['tenant' => 'acme']);
});

it('restores the previous state when the scope throws', function (): void {
    $this->context->set('tenant', 'acme');

    expect(fn (): mixed => $this->context->scope(['tenant' => 'globex'], function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class)
        ->and($this->context->all())->toBe(['tenant' => 'acme']);
});

it('resolves a memoized key once', function (): void {
    $calls = 0;
    $resolve = function () use (&$calls): array {
        $calls++;

        return ['hostname' => 'node-1'];
    };

    expect($this->context->memoize('host', $resolve))->toBe(['hostname' => 'node-1'])
        ->and($this->context->memoize('host', $resolve))->toBe(['hostname' => 'node-1'])
        ->and($calls)->toBe(1);
});

it('forgets what it memoized when it is flushed', function (): void {
    $calls = 0;
    $resolve = function () use (&$calls): array {
        $calls++;

        return [];
    };

    $this->context->memoize('host', $resolve);
    $this->context->flush();
    $this->context->memoize('host', $resolve);

    expect($calls)->toBe(2);
});

it('keeps what it memoized across a scope', function (): void {
    $calls = 0;
    $resolve = function () use (&$calls): array {
        $calls++;

        return [];
    };

    $this->context->memoize('host', $resolve);

    $this->context->scope(['reason' => 'migration'], function () use ($resolve): void {
        $this->context->memoize('host', $resolve);
    });

    expect($calls)->toBe(1);
});
