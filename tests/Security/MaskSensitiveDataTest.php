<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData;
use ElPandaPe\Sentinel\Tests\Fixtures\BlanketMasker;
use ElPandaPe\Sentinel\Tests\Fixtures\FieldNamingMasker;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\protectedEntry;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([MaskSensitiveData::class]);
    config()->set('sentinel.security.redaction.masker', BlanketMasker::class);
});

it('masks the field a model declared, in both snapshots', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'before' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        'after' => ['name' => 'Ada', 'email' => 'grace@example.com'],
    ])));

    expect($audit?->before)->toBe(['name' => 'Ada', 'email' => BlanketMasker::MASK])
        ->and($audit?->after)->toBe(['name' => 'Ada', 'email' => BlanketMasker::MASK]);
});

it('masks both sides of a change while keeping the path that proves it moved', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'changes' => [['path' => '/email', 'op' => 'replace', 'old' => 'ada@example.com', 'new' => 'grace@example.com']],
    ])));

    expect($audit?->changes)->toBe([
        ['path' => '/email', 'op' => 'replace', 'old' => BlanketMasker::MASK, 'new' => BlanketMasker::MASK],
    ]);
});

it('masks a declared field wherever it surfaces, metadata and context included', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'metadata' => ['requested_by' => ['email' => 'ada@example.com']],
        'context' => ['arguments' => ['email' => 'ada@example.com'], 'ip' => '203.0.113.7'],
    ])));

    expect($audit?->metadata)->toBe(['requested_by' => ['email' => BlanketMasker::MASK]])
        ->and($audit?->context)->toBe(['arguments' => ['email' => BlanketMasker::MASK], 'ip' => '203.0.113.7']);
});

it('masks a field nested under a path, not only one at the root', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'changes' => [['path' => '/profile/email', 'op' => 'replace', 'old' => 'a@b.com', 'new' => 'c@d.com']],
    ])));

    expect($audit?->changes)->toBe([
        ['path' => '/profile/email', 'op' => 'replace', 'old' => BlanketMasker::MASK, 'new' => BlanketMasker::MASK],
    ]);
});

it('keeps a change that has no old side without inventing one', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'changes' => [['path' => '/email', 'op' => 'add', 'new' => 'ada@example.com']],
    ])));

    expect($audit?->changes)->toBe([['path' => '/email', 'op' => 'add', 'new' => BlanketMasker::MASK]]);
});

it('leaves a change on a field nobody declared exactly as it was', function (): void {
    $changes = [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']];

    expect(pipeline()->process(auditData(protectedEntry(['changes' => $changes])))?->changes)->toBe($changes);
});

it('digests the field a model declared instead of masking it', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry([
        'before' => ['secret' => 'first'],
        'after' => ['secret' => 'second'],
    ])));

    $before = $audit?->before['secret'] ?? null;
    $after = $audit?->after['secret'] ?? null;

    expect($before)->toBeString()->toHaveLength(64)
        ->and($after)->toBeString()->toHaveLength(64)
        ->and($before)->not->toBe($after)
        ->and($before)->not->toContain('first');
});

it('gives the same value the same digest, which is what makes "did it change" answerable', function (): void {
    $first = pipeline()->process(auditData(protectedEntry(['after' => ['secret' => 'same']])));
    $second = pipeline()->process(auditData(protectedEntry(['after' => ['secret' => 'same']])));

    expect($first?->after)->toBe($second?->after);
});

it('leaves a null alone rather than digesting the absence of a value', function (): void {
    $audit = pipeline()->process(auditData(protectedEntry(['after' => ['secret' => null, 'email' => null]])));

    expect($audit?->after)->toBe(['secret' => null, 'email' => null]);
});

it('protects nothing on an entry whose subject declares nothing', function (): void {
    $audit = pipeline()->process(auditData([
        'subject_type' => 'not-a-model',
        'after' => ['email' => 'ada@example.com', 'secret' => 'first'],
    ]));

    expect($audit?->after)->toBe(['email' => 'ada@example.com', 'secret' => 'first']);
});

it('protects an entry with no subject at all without failing', function (): void {
    $audit = pipeline()->process(auditData(['after' => ['email' => 'ada@example.com']]));

    expect($audit?->after)->toBe(['email' => 'ada@example.com']);
});

it('adds the configured fields to the ones the model declared', function (): void {
    config()->set('sentinel.security.redaction.fields', ['ip']);

    $audit = pipeline()->process(auditData(protectedEntry([
        'context' => ['ip' => '203.0.113.7', 'url' => '/orders'],
        'after' => ['email' => 'ada@example.com'],
    ])));

    expect($audit?->context)->toBe(['ip' => BlanketMasker::MASK, 'url' => '/orders'])
        ->and($audit?->after)->toBe(['email' => BlanketMasker::MASK]);
});

it('protects a configured field on an entry no model owns', function (): void {
    config()->set('sentinel.security.hashing.fields', ['session_id']);

    $audit = pipeline()->process(auditData(['context' => ['session_id' => 'abc']]));

    expect($audit?->context['session_id'] ?? null)->toBeString()->toHaveLength(64)->not->toBe('abc');
});

it('lets one field name override the masker every other field uses', function (): void {
    config()->set('sentinel.security.redaction.fields', ['ip']);
    config()->set('sentinel.security.redaction.maskers', ['ip' => FieldNamingMasker::class]);

    $audit = pipeline()->process(auditData(protectedEntry([
        'context' => ['ip' => '203.0.113.7'],
        'after' => ['email' => 'ada@example.com'],
    ])));

    expect($audit?->context)->toBe(['ip' => '<ip>'])
        ->and($audit?->after)->toBe(['email' => BlanketMasker::MASK]);
});

it('walks past an operation that is not an operation', function (): void {
    $changes = ['not an operation', ['path' => '/email', 'op' => 'add', 'new' => 'ada@example.com']];

    expect(pipeline()->process(auditData(protectedEntry(['changes' => $changes])))?->changes)
        ->toBe(['not an operation', ['path' => '/email', 'op' => 'add', 'new' => BlanketMasker::MASK]]);
});

it('reads an operation with no path as protecting nothing, and still looks inside it', function (): void {
    $changes = [['op' => 'add', 'new' => ['email' => 'ada@example.com']]];

    expect(pipeline()->process(auditData(protectedEntry(['changes' => $changes])))?->changes)
        ->toBe([['op' => 'add', 'new' => ['email' => BlanketMasker::MASK]]]);
});

it('takes the declared list alone when the configured one is absent', function (): void {
    config()->set('sentinel.security.redaction.fields');
    config()->set('sentinel.security.hashing.fields');

    expect(pipeline()->process(auditData(protectedEntry(['after' => ['email' => 'ada@example.com']])))?->after)
        ->toBe(['email' => BlanketMasker::MASK]);
});

it('refuses a configured field list that is not a list', function (): void {
    config()->set('sentinel.security.redaction.fields', 'email');

    pipeline()->process(auditData(protectedEntry()));
})->throws(ConfigurationException::class, 'security.redaction.fields');

it('refuses a configured field name that is not a name', function (): void {
    config()->set('sentinel.security.hashing.fields', [42]);

    pipeline()->process(auditData(protectedEntry()));
})->throws(ConfigurationException::class, 'a list of field names');
