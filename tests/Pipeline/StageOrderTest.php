<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies;
use ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged;
use ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\encryptedEntry;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('ships the order the architecture declares', function (): void {
    expect(Pipeline::DEFAULT_STAGES)->toBe([
        FilterUnchanged::class,
        ResolveContext::class,
        ResolveTags::class,
        NormalizeData::class,
        MaskSensitiveData::class,
        EncryptSensitiveData::class,
        EnforcePolicies::class,
    ]);
});

it('drops an unchanged update while the filter runs on the plaintext', function (): void {
    stagedPipeline([FilterUnchanged::class, EncryptSensitiveData::class]);

    expect(pipeline()->process(auditData(encryptedEntry(['event' => 'updated', 'changes' => []]))))->toBeNull();
});

it('keeps filtering after encryption, because it reads the diff instead of comparing values', function (): void {
    stagedPipeline([EncryptSensitiveData::class, FilterUnchanged::class]);

    expect(pipeline()->process(auditData(encryptedEntry(['event' => 'updated', 'changes' => []]))))->toBeNull();
});

it('is what decides how much work a discarded entry costs', function (): void {
    $unchanged = auditData(encryptedEntry(['event' => 'updated', 'changes' => [], 'after' => ['secret' => 'launch codes']]));

    stagedPipeline([FilterUnchanged::class, EncryptSensitiveData::class]);
    pipeline()->process($unchanged);

    expect($unchanged->after)->toBe(['secret' => 'launch codes'])
        ->and($unchanged->encryption)->toBeNull();

    stagedPipeline([EncryptSensitiveData::class, FilterUnchanged::class]);
    pipeline()->process($unchanged);

    expect($unchanged->after['secret'] ?? null)->not->toBe('launch codes')
        ->and($unchanged->encryption)->not->toBeNull();
});

it('encrypts what the mask already replaced when the two are swapped', function (): void {
    config()->set('sentinel.security.redaction.fields', ['secret']);

    stagedPipeline([MaskSensitiveData::class, EncryptSensitiveData::class]);
    $masked = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    stagedPipeline([EncryptSensitiveData::class, MaskSensitiveData::class]);
    $swapped = pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])));

    expect($masked?->encryption)->toBe(['fields' => ['secret'], 'key_id' => 'default'])
        ->and($swapped?->after['secret'] ?? '')->toBeString()
        ->and($swapped?->after['secret'] ?? '')->not->toStartWith('eyJ');
});

it('lets an application put its own stage between two of the package', function (): void {
    stagedPipeline([
        FilterUnchanged::class,
        ElPandaPe\Sentinel\Tests\Fixtures\StampingStage::class,
        NormalizeData::class,
    ]);

    expect(pipeline()->process(auditData())?->metadata)->toBe(['stamps' => ['first']]);
});

it('lets an application drop a stage by declaring the list without it', function (): void {
    stagedPipeline([ResolveContext::class]);

    expect(pipeline()->process(auditData(encryptedEntry(['after' => ['secret' => 'launch codes']])))?->after)
        ->toBe(['secret' => 'launch codes']);
});
