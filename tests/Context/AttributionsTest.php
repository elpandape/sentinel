<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Attribution;
use ElPandaPe\Sentinel\Context\Attributions;
use ElPandaPe\Sentinel\Support\Reference;

it('holds nothing outside a pass', function (): void {
    expect(new Attributions()->current())->toBeNull();
});

it('holds the attribution for exactly the length of the callback', function (): void {
    $attributions = new Attributions;
    $named = Attribution::by(new Reference('member', '77'));

    $seen = $attributions->within($named, static fn (): ?Attribution => $attributions->current());

    expect($seen)->toBe($named)
        ->and($attributions->current())->toBeNull();
});

it('hands the callback its return value back', function (): void {
    expect(new Attributions()->within(Attribution::none(), static fn (): string => 'written'))->toBe('written');
});

it('answers with the innermost attribution while captures nest, and with the outer one again after', function (): void {
    $attributions = new Attributions;
    $outer = Attribution::by(new Reference('member', '77'));
    $inner = Attribution::none();

    $attributions->within($outer, function () use ($attributions, $outer, $inner): void {
        $attributions->within($inner, static fn (): mixed => expect($attributions->current())->toBe($inner));

        expect($attributions->current())->toBe($outer);
    });
});

it('takes the attribution back when the callback throws', function (): void {
    $attributions = new Attributions;

    rescue(static fn (): mixed => $attributions->within(Attribution::none(), static function (): void {
        throw new RuntimeException('undo');
    }), report: false);

    expect($attributions->current())->toBeNull();
});

it('is one holder per request, from the container', function (): void {
    expect(app(Attributions::class))->toBe(app(Attributions::class));
});
