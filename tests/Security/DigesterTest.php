<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

use function ElPandaPe\Sentinel\Tests\digester;

it('digests a value the same way twice', function (): void {
    expect(digester()->digest('secret'))->toBe(digester()->digest('secret'));
});

it('gives two structures carrying the same facts the same digest', function (): void {
    expect(digester()->digest(['b' => 1, 'a' => 2]))->toBe(digester()->digest(['a' => 2, 'b' => 1]));
});

it('separates the salt from the value so two installations disagree', function (): void {
    expect(digester(['security.hashing.salt' => 'one'])->digest('secret'))
        ->not->toBe(digester(['security.hashing.salt' => 'two'])->digest('secret'));
});

it('honours the declared algorithm', function (): void {
    expect(digester(['security.hashing.algorithm' => 'sha512'])->digest('secret'))->toHaveLength(128);
});

it('leaves a null as the absence of a value', function (): void {
    expect(digester()->digest(null))->toBeNull();
});

it('refuses an algorithm php cannot compute', function (): void {
    digester(['security.hashing.algorithm' => 'rot13'])->digest('secret');
})->throws(ConfigurationException::class, 'security.hashing.algorithm');

it('refuses an algorithm that is not a string', function (): void {
    digester(['security.hashing.algorithm' => 256])->digest('secret');
})->throws(ConfigurationException::class, 'must be a string');

it('refuses a salt that is not a string', function (): void {
    digester(['security.hashing.salt' => ['pepper']])->digest('secret');
})->throws(ConfigurationException::class, 'security.hashing.salt');

it('derives a salt from the application key when none is declared', function (): void {
    expect(digester(['security.hashing.salt' => null])->digest('secret'))->toBeString();
});

it('says what to do when there is no salt and no application key to derive one from', function (): void {
    config()->set('app.key');

    digester(['security.hashing.salt' => null])->digest('secret');
})->throws(ConfigurationException::class, 'key:generate');
