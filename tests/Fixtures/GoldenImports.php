<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * What each row of each source dump means, frozen.
 *
 * The other golden dataset freezes the canonical form of an entry and its hash, because that is the
 * contract with everyone who verifies one. This freezes something else: the contract with the two
 * packages a trail can come from. A mapping that drifts does not fail — it imports, and what lands
 * says something other than what the source meant, which is the failure this whole version is
 * arranged to make impossible.
 *
 * The sealed row cannot be frozen and does not need to be: its identifier is minted at write time
 * and its `created_at` is the instant of the import. What is frozen is everything the source
 * decides, which includes the derived identity that makes a second run cost nothing.
 *
 * A row the origin refuses is frozen as its refusal, for the same reason. Whether a row can be read
 * at all is part of the mapping.
 */
final class GoldenImports
{
    /**
     * @return array<array-key, array<string, mixed>>
     */
    public static function owenIt(): array
    {
        return [
            '1' => [
                'audit_type' => 'model',
                'event' => 'created',
                'severity' => 'info',
                'occurred_at' => '2024-01-02T03:04:05.000000+00:00',
                'source' => 'import',
                'stream' => null,
                'subject_type' => 'App\\Models\\Invoice',
                'subject_id' => '77',
                'actor_type' => 'App\\Models\\User',
                'actor_id' => '7',
                'impersonator_type' => null,
                'impersonator_id' => null,
                'tenant_id' => null,
                'transaction_id' => null,
                'request_id' => null,
                'trace_id' => null,
                'span_id' => null,
                'context' => [
                    'url' => 'http://example.test/invoices',
                    'ip' => '203.0.113.4',
                    'user_agent' => 'Mozilla/5.0',
                ],
                'before' => [],
                'after' => [
                    'number' => 'INV-1',
                    'total' => 100,
                    'status' => 'draft',
                ],
                'changes' => [
                    [
                        'path' => '/number',
                        'op' => 'add',
                        'old' => null,
                        'new' => 'INV-1',
                    ],
                    [
                        'path' => '/status',
                        'op' => 'add',
                        'old' => null,
                        'new' => 'draft',
                    ],
                    [
                        'path' => '/total',
                        'op' => 'add',
                        'old' => null,
                        'new' => 100,
                    ],
                ],
                'metadata' => [
                    'import' => [
                        'origin' => 'owenit',
                        'row' => '1',
                    ],
                ],
                'encryption' => null,
                'criteria' => null,
                'affected_rows' => null,
                'source_audit_id' => null,
                'capture_id' => '62RT0C25KRMJVDN1B9AXR5HPC9',
                'tags' => [
                    'billing',
                    'quarter-one',
                ],
            ],
            '2' => [
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'occurred_at' => '2024-01-03T09:10:11.000000+00:00',
                'source' => 'import',
                'stream' => null,
                'subject_type' => 'App\\Models\\Invoice',
                'subject_id' => '77',
                'actor_type' => 'App\\Models\\User',
                'actor_id' => '7',
                'impersonator_type' => null,
                'impersonator_id' => null,
                'tenant_id' => null,
                'transaction_id' => null,
                'request_id' => null,
                'trace_id' => null,
                'span_id' => null,
                'context' => [
                    'url' => 'http://example.test/invoices/77',
                    'ip' => '203.0.113.4',
                    'user_agent' => 'Mozilla/5.0',
                ],
                'before' => [
                    'status' => 'draft',
                ],
                'after' => [
                    'status' => 'sent',
                ],
                'changes' => [
                    [
                        'path' => '/status',
                        'op' => 'replace',
                        'old' => 'draft',
                        'new' => 'sent',
                    ],
                ],
                'metadata' => [
                    'import' => [
                        'origin' => 'owenit',
                        'row' => '2',
                    ],
                ],
                'encryption' => null,
                'criteria' => null,
                'affected_rows' => null,
                'source_audit_id' => null,
                'capture_id' => '079DB5ETB892A7DBZTNBBTE5SW',
                'tags' => [],
            ],
            '3' => [
                'audit_type' => 'model',
                'event' => 'deleted',
                'severity' => 'notice',
                'occurred_at' => '2024-02-01T00:00:00.000000+00:00',
                'source' => 'import',
                'stream' => null,
                'subject_type' => 'App\\Models\\Invoice',
                'subject_id' => '77',
                'actor_type' => null,
                'actor_id' => null,
                'impersonator_type' => null,
                'impersonator_id' => null,
                'tenant_id' => null,
                'transaction_id' => null,
                'request_id' => null,
                'trace_id' => null,
                'span_id' => null,
                'context' => [
                    'url' => 'artisan invoices:sweep',
                ],
                'before' => [
                    'number' => 'INV-1',
                    'total' => 100,
                    'status' => 'sent',
                ],
                'after' => [],
                'changes' => [
                    [
                        'path' => '/number',
                        'op' => 'remove',
                        'old' => 'INV-1',
                        'new' => null,
                    ],
                    [
                        'path' => '/status',
                        'op' => 'remove',
                        'old' => 'sent',
                        'new' => null,
                    ],
                    [
                        'path' => '/total',
                        'op' => 'remove',
                        'old' => 100,
                        'new' => null,
                    ],
                ],
                'metadata' => [
                    'import' => [
                        'origin' => 'owenit',
                        'row' => '3',
                    ],
                ],
                'encryption' => null,
                'criteria' => null,
                'affected_rows' => null,
                'source_audit_id' => null,
                'capture_id' => '1AYFYN4E8B13PJNR17VH06QQY4',
                'tags' => [
                    'billing',
                ],
            ],
            '4' => [
                'refused' => 'the row does not say when it happened, and an invented instant is worse than no entry',
            ],
        ];
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    public static function altek(): array
    {
        return [
            '1' => [
                'audit_type' => 'model',
                'event' => 'created',
                'severity' => 'info',
                'occurred_at' => '2024-01-02T03:04:05.000000+00:00',
                'source' => 'import',
                'stream' => null,
                'subject_type' => 'App\\Models\\Invoice',
                'subject_id' => '77',
                'actor_type' => 'App\\Models\\User',
                'actor_id' => '7',
                'impersonator_type' => null,
                'impersonator_id' => null,
                'tenant_id' => null,
                'transaction_id' => null,
                'request_id' => null,
                'trace_id' => null,
                'span_id' => null,
                'context' => [
                    'url' => 'http://example.test/invoices',
                    'ip' => '203.0.113.4',
                    'user_agent' => 'Mozilla/5.0',
                ],
                'before' => null,
                'after' => [
                    'number' => 'INV-1',
                    'total' => 100,
                    'status' => 'draft',
                ],
                'changes' => [
                    [
                        'path' => '/number',
                        'op' => 'add',
                        'new' => 'INV-1',
                    ],
                    [
                        'path' => '/total',
                        'op' => 'add',
                        'new' => 100,
                    ],
                    [
                        'path' => '/status',
                        'op' => 'add',
                        'new' => 'draft',
                    ],
                ],
                'metadata' => [
                    'import' => [
                        'origin' => 'altek',
                        'row' => '1',
                        'context' => 4,
                        'signature' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                        'extra' => [
                            'tenant' => 'acme',
                        ],
                    ],
                ],
                'encryption' => null,
                'criteria' => null,
                'affected_rows' => null,
                'source_audit_id' => null,
                'capture_id' => '4QS7THJCKPXX8ZMFKRWVRH3KBD',
                'tags' => [],
            ],
            '2' => [
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'occurred_at' => '2024-01-03T09:10:11.000000+00:00',
                'source' => 'import',
                'stream' => null,
                'subject_type' => 'App\\Models\\Invoice',
                'subject_id' => '77',
                'actor_type' => 'App\\Models\\User',
                'actor_id' => '7',
                'impersonator_type' => null,
                'impersonator_id' => null,
                'tenant_id' => null,
                'transaction_id' => null,
                'request_id' => null,
                'trace_id' => null,
                'span_id' => null,
                'context' => [],
                'before' => null,
                'after' => [
                    'number' => 'INV-1',
                    'total' => 100,
                    'status' => 'sent',
                ],
                'changes' => [
                    [
                        'path' => '/status',
                        'op' => 'replace',
                        'new' => 'sent',
                    ],
                ],
                'metadata' => [
                    'import' => [
                        'origin' => 'altek',
                        'row' => '2',
                        'context' => 2,
                        'signature' => 'd7a8fbb307d7809469ca9abcb0082e4f8d5651e46d3cdb762d02d0bf37c9e592',
                    ],
                ],
                'encryption' => null,
                'criteria' => null,
                'affected_rows' => null,
                'source_audit_id' => null,
                'capture_id' => '799ZNW38CBN5ZNY64J5N2HC05R',
                'tags' => [],
            ],
            '3' => [
                'refused' => 'the row does not say when it happened, and an invented instant is worse than no entry',
            ],
        ];
    }
}
