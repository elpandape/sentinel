<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * A reduced dump of an `altek/accountant` history, as that package writes one.
 *
 * Written against its v5, whose `ledgers` table has not moved a column since v3. What it is here to
 * show is the difference from the other origin: every row carries a whole snapshot in `properties`
 * and a bare list of attribute names in `modified`, and nowhere in any of them is there a value the
 * record held before. The last row has no timestamp, which is the one that cannot become an entry.
 */
final class AltekTrail
{
    public const string TABLE = 'ledgers';

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        return [
            [
                'id' => 1,
                'user_type' => 'App\\Models\\User',
                'user_id' => 7,
                'context' => 4,
                'event' => 'created',
                'recordable_type' => 'App\\Models\\Invoice',
                'recordable_id' => 77,
                'properties' => '{"number":"INV-1","total":100,"status":"draft"}',
                'modified' => '["number","total","status"]',
                'pivot' => '[]',
                'extra' => '{"tenant":"acme"}',
                'url' => 'http://example.test/invoices',
                'ip_address' => '203.0.113.4',
                'user_agent' => 'Mozilla/5.0',
                'signature' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'created_at' => '2024-01-02 03:04:05',
                'updated_at' => '2024-01-02 03:04:05',
            ],
            [
                'id' => 2,
                'user_type' => 'App\\Models\\User',
                'user_id' => 7,
                'context' => 2,
                'event' => 'updated',
                'recordable_type' => 'App\\Models\\Invoice',
                'recordable_id' => 77,
                'properties' => '{"number":"INV-1","total":100,"status":"sent"}',
                'modified' => '["status"]',
                'pivot' => '[]',
                'extra' => '[]',
                'url' => null,
                'ip_address' => null,
                'user_agent' => null,
                'signature' => 'd7a8fbb307d7809469ca9abcb0082e4f8d5651e46d3cdb762d02d0bf37c9e592',
                'created_at' => '2024-01-03 09:10:11',
                'updated_at' => '2024-01-03 09:10:11',
            ],
            [
                'id' => 3,
                'user_type' => null,
                'user_id' => null,
                'context' => 2,
                'event' => 'deleted',
                'recordable_type' => 'App\\Models\\Invoice',
                'recordable_id' => 77,
                'properties' => '{"number":"INV-1","total":100,"status":"sent"}',
                'modified' => '[]',
                'pivot' => '[]',
                'extra' => '[]',
                'url' => null,
                'ip_address' => null,
                'user_agent' => null,
                'signature' => '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae',
                'created_at' => null,
                'updated_at' => null,
            ],
        ];
    }
}
