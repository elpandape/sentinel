<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * A reduced dump of an `owen-it/laravel-auditing` history, as that package writes one.
 *
 * Written against its v14, whose `audits` table has not moved a column since v10. Every quirk the
 * mapping has to survive is in here on purpose: an update that carries only the dirty attributes, a
 * create whose old side is empty, a delete whose new side is, labels joined by commas with a space
 * somebody left in, a row with no actor on it, and a row with no timestamp — which is the one that
 * cannot become an entry at all.
 */
final class OwenItTrail
{
    public const string TABLE = 'audits';

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
                'event' => 'created',
                'auditable_type' => 'App\\Models\\Invoice',
                'auditable_id' => 77,
                'old_values' => '[]',
                'new_values' => '{"number":"INV-1","total":100,"status":"draft"}',
                'url' => 'http://example.test/invoices',
                'ip_address' => '203.0.113.4',
                'user_agent' => 'Mozilla/5.0',
                'tags' => 'billing, quarter-one',
                'created_at' => '2024-01-02 03:04:05',
                'updated_at' => '2024-01-02 03:04:05',
            ],
            [
                'id' => 2,
                'user_type' => 'App\\Models\\User',
                'user_id' => 7,
                'event' => 'updated',
                'auditable_type' => 'App\\Models\\Invoice',
                'auditable_id' => 77,
                'old_values' => '{"status":"draft"}',
                'new_values' => '{"status":"sent"}',
                'url' => 'http://example.test/invoices/77',
                'ip_address' => '203.0.113.4',
                'user_agent' => 'Mozilla/5.0',
                'tags' => null,
                'created_at' => '2024-01-03 09:10:11',
                'updated_at' => '2024-01-03 09:10:11',
            ],
            [
                'id' => 3,
                'user_type' => null,
                'user_id' => null,
                'event' => 'deleted',
                'auditable_type' => 'App\\Models\\Invoice',
                'auditable_id' => 77,
                'old_values' => '{"number":"INV-1","total":100,"status":"sent"}',
                'new_values' => '[]',
                'url' => 'artisan invoices:sweep',
                'ip_address' => null,
                'user_agent' => null,
                'tags' => 'billing',
                'created_at' => '2024-02-01 00:00:00',
                'updated_at' => '2024-02-01 00:00:00',
            ],
            [
                'id' => 4,
                'user_type' => 'App\\Models\\User',
                'user_id' => 9,
                'event' => 'updated',
                'auditable_type' => 'App\\Models\\Invoice',
                'auditable_id' => 78,
                'old_values' => '{"total":50}',
                'new_values' => '{"total":75}',
                'url' => null,
                'ip_address' => null,
                'user_agent' => null,
                'tags' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];
    }
}
