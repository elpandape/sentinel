<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * A chain that chains. Sibling of GoldenLedger, which freezes payload shapes and whose links are
 * deliberately synthetic — its streams jump sequences and borrow hashes from each other, so it
 * proves canonicalization and proves nothing about linkage.
 *
 * Here the only thing frozen is the linkage: one dense, monotonic stream where every previous_hash
 * is literally the hash of the row before it, and a second stream that shares nothing with it. That
 * is what a range signature and a range anchor need underneath them.
 */
final class ReferenceChain
{
    public const string STREAM = 'reference';

    public const string FORK = 'reference:other';

    /**
     * The roots of the ranges, frozen the way the hashes are. The main stream anchors in windows of
     * four and the fork in one window of two; ROOT_5_8 folds ROOT_1_4 into itself, so the pair is
     * also what a chain of anchors looks like.
     */
    public const string ROOT_1_4 = '95eb29a2e90e4dd86ea7f663b9bc113e9a4a65d636f204effad17f5055d38097';

    public const string ROOT_5_8 = '52bd23e1c7e687a8930d86302cada8e857551e291116eaebae0c07861fda64df';

    public const string FORK_ROOT_1_2 = '11471dd6dacaf9fd7b43e898505587508b6dd7f2fe087445004e4621994fb56f';

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        return [
            [
                'id' => '01JCHAIN0000000000000000S1',
                'stream' => self::STREAM,
                'sequence' => 1,
                'audit_type' => 'model',
                'event' => 'created',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '1',
                'version' => 1,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:00.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => null,
                'hash' => '390caaefd028e73e281f89a9b4a02a1bb45acd327b34a90c1686b6bab5f17de3',
            ],
            [
                'id' => '01JCHAIN0000000000000000S2',
                'stream' => self::STREAM,
                'sequence' => 2,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '1',
                'version' => 2,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:01.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => '390caaefd028e73e281f89a9b4a02a1bb45acd327b34a90c1686b6bab5f17de3',
                'hash' => '4c23b2ccc500906c6f4ddded612d406b8bff906f49c823eaa333d44483a0b7b1',
            ],
            [
                'id' => '01JCHAIN0000000000000000S3',
                'stream' => self::STREAM,
                'sequence' => 3,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'source' => 'http',
                'subject_type' => 'subject',
                'subject_id' => '1',
                'version' => 3,
                'context' => ['ip' => '203.0.113.7'],
                'occurred_at' => '2026-08-29 09:00:02.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => '4c23b2ccc500906c6f4ddded612d406b8bff906f49c823eaa333d44483a0b7b1',
                'hash' => '2df1120cdec473ec377f432bac9f85f6af9822a6d9bbe3aaa59f2944aa5d8684',
            ],
            [
                'id' => '01JCHAIN0000000000000000S4',
                'stream' => self::STREAM,
                'sequence' => 4,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'warning',
                'source' => 'http',
                'subject_type' => 'subject',
                'subject_id' => '1',
                'version' => 4,
                'context' => ['ip' => '203.0.113.7'],
                'changes' => [['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'sent']],
                'occurred_at' => '2026-08-29 09:00:03.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => '2df1120cdec473ec377f432bac9f85f6af9822a6d9bbe3aaa59f2944aa5d8684',
                'hash' => 'dd8bcf004e2c215638aaf63cc98d0768dadd8c8f94503dce69e2cf5d70420138',
            ],
            [
                'id' => '01JCHAIN0000000000000000S5',
                'stream' => self::STREAM,
                'sequence' => 5,
                'audit_type' => 'command',
                'event' => 'custom',
                'severity' => 'info',
                'source' => 'cli',
                'context' => ['command' => 'reference:run'],
                'occurred_at' => '2026-08-29 09:00:04.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => 'dd8bcf004e2c215638aaf63cc98d0768dadd8c8f94503dce69e2cf5d70420138',
                'hash' => 'a317906656d2730f0683624755e7021f8399a04932d1df39fe8cd04768bffc16',
            ],
            [
                'id' => '01JCHAIN0000000000000000S6',
                'stream' => self::STREAM,
                'sequence' => 6,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '2',
                'version' => 1,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:05.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => 'a317906656d2730f0683624755e7021f8399a04932d1df39fe8cd04768bffc16',
                'hash' => 'ebad62bfd6c3c170229dc658feac4ffa576b6f9e3c0c97c4a10933c93bae1374',
            ],
            [
                'id' => '01JCHAIN0000000000000000S7',
                'stream' => self::STREAM,
                'sequence' => 7,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '2',
                'version' => 2,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:06.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => 'ebad62bfd6c3c170229dc658feac4ffa576b6f9e3c0c97c4a10933c93bae1374',
                'hash' => 'e9b8bd9a4865f7b5022718a2021bc78c4734827bbd0253fe989ef0abe5bf1637',
            ],
            [
                'id' => '01JCHAIN0000000000000000S8',
                'stream' => self::STREAM,
                'sequence' => 8,
                'audit_type' => 'model',
                'event' => 'deleted',
                'severity' => 'critical',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '2',
                'version' => 3,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:07.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => 'e9b8bd9a4865f7b5022718a2021bc78c4734827bbd0253fe989ef0abe5bf1637',
                'hash' => 'f38a4da58000e93dc11915df82714a96d5fe00930badae9beca09beeae921482',
            ],
            [
                'id' => '01JCHAIN0000000000000000F1',
                'stream' => self::FORK,
                'sequence' => 1,
                'audit_type' => 'model',
                'event' => 'created',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '9',
                'version' => 1,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:08.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => null,
                'hash' => '49180188a54354fe2c2c8fdbd13900431230c8c1e61eb6324a9bd9b2bfbab93c',
            ],
            [
                'id' => '01JCHAIN0000000000000000F2',
                'stream' => self::FORK,
                'sequence' => 2,
                'audit_type' => 'model',
                'event' => 'updated',
                'severity' => 'info',
                'source' => 'system',
                'subject_type' => 'subject',
                'subject_id' => '9',
                'version' => 2,
                'context' => [],
                'occurred_at' => '2026-08-29 09:00:09.000000',
                'payload_version' => 1,
                'algorithm' => 'sha256',
                'previous_hash' => '49180188a54354fe2c2c8fdbd13900431230c8c1e61eb6324a9bd9b2bfbab93c',
                'hash' => '118cb45413d71565821134d6c29771abafb6c846a1ce1de8990c87c598763fae',
            ],
        ];
    }
}
