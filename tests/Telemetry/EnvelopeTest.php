<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Telemetry\Envelope;
use ElPandaPe\Sentinel\Telemetry\TraceContext;

use function ElPandaPe\Sentinel\Tests\traceParent;

it('seals nothing when there is nothing to seal', function (): void {
    expect(Envelope::seal(null, null))->toBeEmpty();
});

it('seals the operation even when nothing traced the request', function (): void {
    expect(Envelope::seal(null, '01JTRANSACTION000000000000'))
        ->toBe(['transaction_id' => '01JTRANSACTION000000000000']);
});

it('seals the tracestate alongside the traceparent when there is one', function (): void {
    expect(Envelope::seal(new TraceContext(traceParent(), 'rojo=1'), null))->toBe([
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'rojo=1',
    ]);
});

it('seals no tracestate when none arrived', function (): void {
    expect(Envelope::seal(new TraceContext(traceParent()), null))
        ->toBe(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
});

it('reads back what it sealed', function (): void {
    $envelope = new Envelope;
    $envelope->receive(Envelope::seal(new TraceContext(traceParent(), 'rojo=1'), '01JTRANSACTION000000000000'));

    expect($envelope->trace()?->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($envelope->trace()?->tracestate())->toBe('rojo=1')
        ->and($envelope->transactionId())->toBe('01JTRANSACTION000000000000');
});

it('reads an envelope that never arrived as no trace and no operation', function (mixed $carried): void {
    $envelope = new Envelope;
    $envelope->receive($carried);

    expect($envelope->trace())->toBeNull()
        ->and($envelope->transactionId())->toBeNull();
})->with([
    'absent' => [null],
    'not an envelope' => ['sentinel'],
    'empty' => [[]],
    'unreadable traceparent' => [['traceparent' => 'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']],
    'traceparent that is not a string' => [['traceparent' => 42]],
]);

it('ignores a tracestate that is not a string', function (): void {
    $envelope = new Envelope;
    $envelope->receive([
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 42,
    ]);

    expect($envelope->trace()?->tracestate())->toBeNull();
});

it('ignores an operation identifier that is not one', function (): void {
    $envelope = new Envelope;
    $envelope->receive(['transaction_id' => 42]);

    expect($envelope->transactionId())->toBeNull();
});
