<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags;
use ElPandaPe\Sentinel\Tests\Fixtures\TaggedSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([ResolveTags::class]);
});

it('classifies an entry with what the model declares', function (): void {
    $audit = pipeline()->process(auditData(['subject_type' => TaggedSubject::class]));

    expect($audit?->tags)->toBe(['billing', 'refund']);
});

it('classifies an entry with what the configuration gives everything', function (): void {
    config()->set('sentinel.tags.default', ['environment:test']);

    $audit = pipeline()->process(auditData());

    expect($audit?->tags)->toBe(['environment:test']);
});

it('keeps the model order in front of the configuration order', function (): void {
    config()->set('sentinel.tags.default', ['audited']);

    $audit = pipeline()->process(auditData(['subject_type' => TaggedSubject::class]));

    expect($audit?->tags)->toBe(['billing', 'refund', 'audited']);
});

it('keeps what the caller already put there', function (): void {
    config()->set('sentinel.tags.default', ['audited']);

    $audit = pipeline()->process(auditData(['tags' => ['manual']]));

    expect($audit?->tags)->toBe(['manual', 'audited']);
});

it('says a label once however many places declare it', function (): void {
    config()->set('sentinel.tags.default', ['billing']);

    $audit = pipeline()->process(auditData(['subject_type' => TaggedSubject::class, 'tags' => ['billing']]));

    expect($audit?->tags)->toBe(['billing', 'refund']);
});

it('classifies nothing when the model and the configuration declare nothing', function (): void {
    expect(pipeline()->process(auditData())?->tags)->toBe([]);
});

it('leaves the labels alone when tagging is turned off', function (): void {
    config()->set('sentinel.tags.enabled', false);
    config()->set('sentinel.tags.default', ['environment:test']);

    expect(pipeline()->process(auditData())?->tags)->toBe([]);
});

it('refuses a label longer than the column holds', function (): void {
    config()->set('sentinel.tags.default', [str_repeat('a', ResolveTags::MAX_LENGTH + 1)]);

    expect(fn () => pipeline()->process(auditData()))->toThrow(ConfigurationException::class);
});

it('accepts a label of exactly the length the column holds', function (): void {
    $tag = str_repeat('a', ResolveTags::MAX_LENGTH);
    config()->set('sentinel.tags.default', [$tag]);

    expect(pipeline()->process(auditData())?->tags)->toBe([$tag]);
});

it('measures a label in characters, not in bytes', function (): void {
    $tag = str_repeat('ñ', ResolveTags::MAX_LENGTH);
    config()->set('sentinel.tags.default', [$tag]);

    expect(pipeline()->process(auditData())?->tags)->toBe([$tag]);
});
