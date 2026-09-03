<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Telemetry\TraceParent;

use function ElPandaPe\Sentinel\Tests\traceParent;

it('takes the three fields of a well formed header', function (): void {
    $parent = traceParent();

    expect($parent->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($parent->spanId)->toBe('00f067aa0ba902b7')
        ->and($parent->flags)->toBe('01')
        ->and($parent->value())->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
});

it('reads the sampled bit off the flags', function (string $flags, bool $sampled): void {
    expect(TraceParent::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-'.$flags)?->sampled())
        ->toBe($sampled);
})->with([
    ['01', true],
    ['00', false],
    ['03', true],
    ['02', false],
]);

it('refuses version ff however well formed the rest is', function (): void {
    expect(TraceParent::parse('ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))->toBeNull();
});

it('parses a higher version by its first three fields and ignores what it appended', function (): void {
    $parent = traceParent('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-whatever');

    expect($parent->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($parent->spanId)->toBe('00f067aa0ba902b7');
});

it('accepts a higher version that appended nothing at all', function (): void {
    expect(TraceParent::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))->not->toBeNull();
});

it('refuses a higher version whose extra fields do not start with a dash', function (): void {
    expect(TraceParent::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01x'))->toBeNull();
});

it('refuses a header it cannot read', function (string $header): void {
    expect(TraceParent::parse($header))->toBeNull();
})->with([
    'too short' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-0'],
    'trailing newline' => ["00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\n"],
    'trailing space' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01 '],
    'uppercase trace id' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'],
    'uppercase span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00F067AA0BA902B7-01'],
    'uppercase flags' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-0A'],
    'non hex version' => ['zz-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'first separator' => ['00x4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'second separator' => ['00-4bf92f3577b34da6a3ce929d0e0e4736x00f067aa0ba902b7-01'],
    'third separator' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7x01'],
    'all zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'all zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
]);

it('builds a root trace nobody handed it', function (): void {
    $root = TraceParent::root();

    expect($root->traceId)->toHaveLength(32)
        ->and($root->spanId)->toHaveLength(16)
        ->and($root->sampled())->toBeFalse()
        ->and(TraceParent::parse($root->value())?->traceId)->toBe($root->traceId);
});

it('gives two roots two identifiers', function (): void {
    expect(TraceParent::root()->traceId)->not->toBe(TraceParent::root()->traceId);
});

it('builds from identifiers it validates just as hard', function (): void {
    expect(TraceParent::of('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7')?->value())
        ->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00')
        ->and(TraceParent::of('short', '00f067aa0ba902b7'))->toBeNull()
        ->and(TraceParent::of('4bf92f3577b34da6a3ce929d0e0e4736', 'short'))->toBeNull()
        ->and(TraceParent::of('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', 'zz'))->toBeNull();
});
