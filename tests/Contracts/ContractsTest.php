<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Auditable;
use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Contracts\StreamResolver;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditableSubject;

use function ElPandaPe\Sentinel\Tests\insertAudit;

it('declares the ledger surface the rest of the roadmap programs against', function (): void {
    expect(get_class_methods(Ledger::class))
        ->toEqualCanonicalizing(['write', 'writeMany', 'find', 'query', 'stream']);
});

it('declares a stream that can be walked in order and bounded by range', function (): void {
    expect(get_class_methods(LedgerStream::class))->toContain('name', 'range', 'getIterator')
        ->and(is_subclass_of(LedgerStream::class, Traversable::class))->toBeTrue();
});

it('declares a stream resolver that names the chain an entry belongs to', function (): void {
    expect(get_class_methods(StreamResolver::class))->toBe(['resolve']);
});

it('declares the field policies an auditable model answers for', function (): void {
    expect(get_class_methods(Auditable::class))->toEqualCanonicalizing([
        'audits',
        'auditIncluded',
        'auditExcluded',
        'auditRedacted',
        'auditEncrypted',
        'auditHashed',
        'auditSnapshotsEnabled',
        'auditSeverity',
    ]);
});

it('declares a resolver that returns the fragment it resolves', function (): void {
    expect(get_class_methods(Resolver::class))->toBe(['resolve']);
});

it('declares a pipeline stage that can discard', function (): void {
    expect(get_class_methods(Transformer::class))->toBe(['handle']);
});

it('declares a signer that signs the hash and names its key', function (): void {
    expect(get_class_methods(Signer::class))->toEqualCanonicalizing(['sign', 'verify', 'keyId']);
});

it('declares a canonicalizer that turns a payload into one string', function (): void {
    expect(get_class_methods(Canonicalizer::class))->toBe(['canonicalize']);
});

it('lets a model implement the auditable contract and reach its own entries', function (): void {
    $subject = AuditableSubject::query()->create();

    insertAudit(['subject_type' => $subject::class, 'subject_id' => (string) $subject->getKey()]);

    expect($subject->audits()->count())->toBe(1)
        ->and($subject->auditExcluded())->toBe(['remember_token'])
        ->and($subject->auditRedacted())->toBe(['email'])
        ->and($subject->auditSnapshotsEnabled())->toBeTrue()
        ->and($subject->auditSeverity())->toBeNull();
});
