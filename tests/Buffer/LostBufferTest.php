<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Buffer\RedisBuffer;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Contracts\Redis\Factory as Redis;

use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 3);
});

it('loses exactly what never reached the ledger, and nothing that did', function (): void {
    foreach (['Ada', 'Grace', 'Barbara'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    new AuditedSubject()->forceFill(['name' => 'Katherine'])->save();
    new AuditedSubject()->forceFill(['name' => 'Margaret'])->save();

    expect(Audit::query()->count())->toBe(3)
        ->and(app(Buffer::class)->size())->toBe(2);

    app()->instance(Buffer::class, new MemoryBuffer);

    expect(Audit::query()->pluck('event')->count())->toBe(3)
        ->and(app(Buffer::class)->size())->toBe(0);
});

it('leaves a chain that still verifies after the loss', function (): void {
    foreach (['Ada', 'Grace', 'Barbara', 'Katherine'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    app()->instance(Buffer::class, new MemoryBuffer);

    expect(verifier()->verifyStream('global')->isIntact())->toBeTrue()
        ->and(Audit::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('leaves no gap for the verification to find, which is why the chain cannot report the loss', function (): void {
    foreach (['Ada', 'Grace', 'Barbara', 'Katherine'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    app()->instance(Buffer::class, new MemoryBuffer);

    new AuditedSubject()->forceFill(['name' => 'Margaret'])->save();
    app()->terminate();

    $result = verifier()->verifyStream('global');

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(4)
        ->and(Audit::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3, 4]);
});

it('loses what a real buffer was holding when the key goes away', function (): void {
    $key = 'sentinel:test:'.getmypid();
    $redis = app(Redis::class)->connection();

    config()->set('sentinel.buffer.size', 500);
    app()->instance(Buffer::class, new RedisBuffer($redis, $key));

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(app(Buffer::class)->size())->toBe(1);

    $redis->command('del', [$key]);

    app()->terminate();

    expect(Audit::query()->count())->toBe(0)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});
