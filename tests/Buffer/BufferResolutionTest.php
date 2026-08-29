<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Buffer\RedisBuffer;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('resolves the redis buffer by default', function (): void {
    expect(app(Buffer::class))->toBeInstanceOf(RedisBuffer::class);
});

it('resolves the one that keeps everything on the instance when asked for it', function (): void {
    config()->set('sentinel.buffer.store', 'memory');

    expect(app(Buffer::class))->toBeInstanceOf(MemoryBuffer::class);
});

it('refuses a store it does not know rather than fall back to the process', function (): void {
    config()->set('sentinel.buffer.store', 'elsewhere');

    expect(static fn (): Buffer => app(Buffer::class))
        ->toThrow(ConfigurationException::class, 'unknown value [elsewhere]');
});

it('reads the redis connection the configuration names', function (): void {
    config()->set('database.redis.audits', config()->get('database.redis.default'));
    config()->set('sentinel.buffer.connection', 'audits');

    expect(app(Buffer::class))->toBeInstanceOf(RedisBuffer::class);
});

it('falls back on its own defaults where a published section has no key for them', function (): void {
    $config = sentinelConfig(['buffer' => []]);

    expect($config->bufferStore())->toBe('redis')
        ->and($config->bufferConnection())->toBeNull()
        ->and($config->bufferKey())->toBe('sentinel:buffer')
        ->and($config->bufferSize())->toBe(500)
        ->and($config->bufferInterval())->toBe(60);
});

it('reads the thresholds the configuration sets', function (): void {
    $config = sentinelConfig(['buffer' => ['size' => 25, 'flush_interval' => 5]]);

    expect($config->bufferSize())->toBe(25)
        ->and($config->bufferInterval())->toBe(5);
});

it('holds both thresholds above nothing, because flushing every nothing entries is not a threshold', function (): void {
    $config = sentinelConfig(['buffer' => ['size' => 0, 'flush_interval' => 0]]);

    expect($config->bufferSize())->toBe(1)
        ->and($config->bufferInterval())->toBe(1);
});

it('refuses a threshold that is not a number', function (): void {
    expect(static fn (): int => sentinelConfig(['buffer' => ['size' => 'many']])->bufferSize())
        ->toThrow(ConfigurationException::class, 'must be an integer or null');
});
