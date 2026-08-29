<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Buffer\RedisBuffer;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;
use Illuminate\Contracts\Redis\Factory as Redis;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frozenUlid;

dataset('buffers', [
    'memory' => [fn (): Buffer => new MemoryBuffer],
    'redis' => [fn (): Buffer => new RedisBuffer(app(Redis::class)->connection(), 'sentinel:test:'.getmypid())],
]);

afterEach(function (): void {
    app(Redis::class)->connection()->command('del', ['sentinel:test:'.getmypid()]);
});

it('holds nothing until something is pushed', function (Closure $make): void {
    $buffer = $make();

    expect($buffer->size())->toBe(0)
        ->and($buffer->oldest())->toBeNull()
        ->and($buffer->take(10))->toBeEmpty();
})->with('buffers');

it('counts what is waiting', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData());
    $buffer->push(auditData());

    expect($buffer->size())->toBe(2);
})->with('buffers');

it('hands entries back oldest first', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData(['capture_id' => frozenUlid('FIRST001')]));
    $buffer->push(auditData(['capture_id' => frozenUlid('SECOND01')]));

    expect(array_map(static fn (AuditData $a): ?string => $a->capture_id, $buffer->take(10)))
        ->toBe([frozenUlid('FIRST001'), frozenUlid('SECOND01')]);
})->with('buffers');

it('removes what it hands over', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData());
    $buffer->take(10);

    expect($buffer->size())->toBe(0);
})->with('buffers');

it('takes no more than it was asked for, and leaves the rest', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData(['capture_id' => frozenUlid('FIRST001')]));
    $buffer->push(auditData(['capture_id' => frozenUlid('SECOND01')]));
    $buffer->push(auditData(['capture_id' => frozenUlid('THIRD001')]));

    $taken = $buffer->take(2);

    expect($taken)->toHaveCount(2)
        ->and($buffer->size())->toBe(1)
        ->and($buffer->take(10)[0]->capture_id)->toBe(frozenUlid('THIRD001'));
})->with('buffers');

it('takes nothing for a limit of nothing', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData());

    expect($buffer->take(0))->toBeEmpty()
        ->and($buffer->size())->toBe(1);
})->with('buffers');

it('says when the oldest thing waiting happened', function (Closure $make): void {
    $buffer = $make();

    $buffer->push(auditData(['occurred_at' => new DateTimeImmutable('2026-08-29 10:00:00.000001')]));
    $buffer->push(auditData(['occurred_at' => new DateTimeImmutable('2026-08-29 11:00:00.000002')]));

    expect($buffer->oldest()?->format('Y-m-d H:i:s.u'))->toBe('2026-08-29 10:00:00.000001');
})->with('buffers');

it('carries every field of the capture through the wait', function (Closure $make): void {
    $buffer = $make();
    $audit = auditData([
        'subject_type' => 'invoice',
        'subject_id' => '500',
        'tenant_id' => 'acme',
        'context' => ['ip' => '203.0.113.7'],
        'before' => ['name' => 'Ada'],
        'changes' => [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']],
        'tags' => ['billing'],
    ]);

    $buffer->push($audit);

    expect($buffer->take(1)[0]->toPayload())->toBe($audit->toPayload());
})->with('buffers');

it('drops an element it did not write rather than abandon the entries behind it', function (): void {
    $key = 'sentinel:test:'.getmypid();
    $buffer = new RedisBuffer(app(Redis::class)->connection(), $key);

    app(Redis::class)->connection()->command('rpush', [$key, 'not json at all']);
    $buffer->push(auditData(['capture_id' => frozenUlid('SURVIVOR')]));

    $taken = $buffer->take(10);

    expect($taken)->toHaveCount(1)
        ->and($taken[0]->capture_id)->toBe(frozenUlid('SURVIVOR'));
});
