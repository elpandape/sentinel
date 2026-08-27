<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Security\PartialMasker;

it('keeps the shape of an address and none of the address', function (): void {
    expect(new PartialMasker('*')->mask('email', 'carlos@example.com'))
        ->toBe('c****s@e****e.c****m');
});

it('keeps the first and last character of each run', function (): void {
    expect(new PartialMasker('*')->mask('name', 'Ada Lovelace'))->toBe('A****a L****e');
});

it('replaces a run too short to keep both ends of', function (): void {
    expect(new PartialMasker('*')->mask('initials', 'A. B.'))->toBe('****. ****.');
});

it('masks a fixed width, so the length of the secret stays secret', function (): void {
    $masker = new PartialMasker('*');

    expect($masker->mask('secret', 'abcdefghijklmnoz'))->toBe($masker->mask('secret', 'abz'));
});

it('takes the mask character from configuration', function (): void {
    expect(new PartialMasker('#')->mask('email', 'ada@b.com'))->toBe('a####a@####.c####m');
});

it('masks a number without pretending it was never a number', function (): void {
    expect(new PartialMasker('*')->mask('score', 1234567))->toBe('1****7');
});

it('leaves a null as the absence of a value', function (): void {
    expect(new PartialMasker('*')->mask('email', null))->toBeNull();
});

it('masks every value of a structure, not the structure', function (): void {
    expect(new PartialMasker('*')->mask('profile', ['email' => 'ada@b.com', 'city' => 'London']))
        ->toBe(['email' => 'a****a@****.c****m', 'city' => 'L****n']);
});

it('gives an unrepresentable value the mask and nothing else', function (): void {
    expect(new PartialMasker('*')->mask('handle', new stdClass))->toBe('****');
});
