<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Security\PartialMasker;
use ElPandaPe\Sentinel\Tests\Fixtures\BlanketMasker;
use ElPandaPe\Sentinel\Tests\Fixtures\FieldNamingMasker;

use function ElPandaPe\Sentinel\Tests\maskers;

it('ships one masker and uses it for every field', function (): void {
    expect(maskers()->for('email'))->toBeInstanceOf(PartialMasker::class);
});

it('lets a field name win over the default masker', function (): void {
    $maskers = maskers([
        'security.redaction.masker' => BlanketMasker::class,
        'security.redaction.maskers' => ['email' => FieldNamingMasker::class],
    ]);

    expect($maskers->for('email'))->toBeInstanceOf(FieldNamingMasker::class)
        ->and($maskers->for('secret'))->toBeInstanceOf(BlanketMasker::class);
});

it('builds a masker once per field', function (): void {
    $maskers = maskers();

    expect($maskers->for('email'))->toBe($maskers->for('email'));
});

it('refuses a masker that is not a class-string', function (): void {
    maskers(['security.redaction.masker' => 42])->for('email');
})->throws(ConfigurationException::class, 'security.redaction.masker');

it('refuses a class that is not a masker', function (): void {
    maskers(['security.redaction.masker' => stdClass::class])->for('email');
})->throws(ConfigurationException::class, 'Masker');

it('names the field when the override is the one at fault', function (): void {
    maskers(['security.redaction.maskers' => ['email' => stdClass::class]])->for('email');
})->throws(ConfigurationException::class, 'security.redaction.maskers.email');
