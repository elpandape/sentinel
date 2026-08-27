<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * Frozen entries of payload_version 1, each with the canonical string it produces and the
 * hash of that string. Every future version that touches the canonical payload is measured
 * against these: while they reproduce, payload_version 1 still means what it meant.
 */
final class GoldenLedger
{
    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function entries(): array
    {
        return [
            'an entry that opens a chain' => [
                [
                    'id' => '01JGOLDEN000000000000000A1',
                    'stream' => 'global',
                    'sequence' => 1,
                    'audit_type' => 'model',
                    'event' => 'created',
                    'severity' => 'info',
                    'source' => 'system',
                    'context' => [],
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => null,
                    'occurred_at' => '2026-08-26 10:00:00.000000',
                ],
                '{"actor_id":null,"actor_type":null,"affected_rows":null,"after":null,"audit_type":"model","before":null,"changes":null,"context":[],"criteria":null,"encryption":null,"event":"created","id":"01JGOLDEN000000000000000A1","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-26 10:00:00.000000","request_id":null,"severity":"info","source":"system","source_audit_id":null,"span_id":null,"subject_id":null,"subject_type":null,"tenant_id":null,"trace_id":null,"transaction_id":null,"version":null}',
                '752d2a1b0ededff42112fde4b1429d2d5e11ffe85ff664a41fe0e9730d5adbfe',
            ],
            'an entry with every json column populated' => [
                [
                    'id' => '01JGOLDEN000000000000000B2',
                    'stream' => 'tenant:acme',
                    'sequence' => 42,
                    'audit_type' => 'model',
                    'event' => 'updated',
                    'severity' => 'warning',
                    'source' => 'http',
                    'subject_type' => 'user',
                    'subject_id' => '7',
                    'actor_type' => 'user',
                    'actor_id' => '1',
                    'tenant_id' => 'acme',
                    'version' => 3,
                    'context' => ['ip' => '203.0.113.7', 'locale' => 'es'],
                    'before' => ['name' => 'José', 'score' => 0.1],
                    'after' => ['name' => '海', 'score' => 9007199254740993],
                    'changes' => ['name' => ['José', '海']],
                    'metadata' => ['tags' => ['b', 'a'], 'nested' => ['z' => 1, 'a' => 2]],
                    'encryption' => ['fields' => ['name'], 'key_id' => 'default'],
                    'criteria' => ['where' => ['id' => 7]],
                    'affected_rows' => 1,
                    'source_audit_id' => '01JGOLDEN000000000000000A1',
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    'occurred_at' => '2026-08-26 10:00:00.123456',
                ],
                '{"actor_id":"1","actor_type":"user","affected_rows":1,"after":{"name":"海","score":9007199254740993},"audit_type":"model","before":{"name":"José","score":0.1},"changes":{"name":["José","海"]},"context":{"ip":"203.0.113.7","locale":"es"},"criteria":{"where":{"id":7}},"encryption":{"fields":["name"],"key_id":"default"},"event":"updated","id":"01JGOLDEN000000000000000B2","impersonator_id":null,"impersonator_type":null,"metadata":{"nested":{"a":2,"z":1},"tags":["b","a"]},"occurred_at":"2026-08-26 10:00:00.123456","request_id":null,"severity":"warning","source":"http","source_audit_id":"01JGOLDEN000000000000000A1","span_id":null,"subject_id":"7","subject_type":"user","tenant_id":"acme","trace_id":null,"transaction_id":null,"version":3}',
                'df0064e2bb4ed13d0cfe07fe7ad66acfaaf5801b79f13adc1f5efa59c61c947c',
            ],
            'an entry that continues a chain' => [
                [
                    'id' => '01JGOLDEN000000000000000C3',
                    'stream' => 'global',
                    'sequence' => 2,
                    'audit_type' => 'command',
                    'event' => 'custom',
                    'severity' => 'critical',
                    'source' => 'cli',
                    'context' => ['command' => 'sentinel:verify'],
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709da39a3ee5e6b4b0d32550000',
                    'occurred_at' => '2026-08-26 23:59:59.999999',
                ],
                '{"actor_id":null,"actor_type":null,"affected_rows":null,"after":null,"audit_type":"command","before":null,"changes":null,"context":{"command":"sentinel:verify"},"criteria":null,"encryption":null,"event":"custom","id":"01JGOLDEN000000000000000C3","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-26 23:59:59.999999","request_id":null,"severity":"critical","source":"cli","source_audit_id":null,"span_id":null,"subject_id":null,"subject_type":null,"tenant_id":null,"trace_id":null,"transaction_id":null,"version":null}',
                'b86189bf5d9578a7db1fee3742eb90ae6682560e3bc5a713167e1e826874c24d',
            ],
            'a model entry with both snapshots populated' => [
                [
                    'id' => '01JGOLDEN000000000000000D4',
                    'stream' => 'global',
                    'sequence' => 3,
                    'audit_type' => 'model',
                    'event' => 'updated',
                    'severity' => 'info',
                    'source' => 'system',
                    'subject_type' => 'subject',
                    'subject_id' => '1',
                    'version' => 2,
                    'context' => [],
                    'before' => ['active' => true, 'name' => 'Ada', 'published_at' => '2026-08-26T10:00:00.123456+00:00'],
                    'after' => ['active' => false, 'name' => 'Grace', 'published_at' => null],
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => 'b86189bf5d9578a7db1fee3742eb90ae6682560e3bc5a713167e1e826874c24d',
                    'occurred_at' => '2026-08-26 10:00:00.000000',
                ],
                '{"actor_id":null,"actor_type":null,"affected_rows":null,"after":{"active":false,"name":"Grace","published_at":null},"audit_type":"model","before":{"active":true,"name":"Ada","published_at":"2026-08-26T10:00:00.123456+00:00"},"changes":null,"context":[],"criteria":null,"encryption":null,"event":"updated","id":"01JGOLDEN000000000000000D4","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-26 10:00:00.000000","request_id":null,"severity":"info","source":"system","source_audit_id":null,"span_id":null,"subject_id":"1","subject_type":"subject","tenant_id":null,"trace_id":null,"transaction_id":null,"version":2}',
                '729aaf38a9811bb46f582c262d4e4e2ab4a7509dc9cb4989d8356e8508402402',
            ],
            'an entry carrying the diff of its own change' => [
                [
                    'id' => '01JGOLDEN000000000000000E5',
                    'stream' => 'global',
                    'sequence' => 4,
                    'audit_type' => 'model',
                    'event' => 'updated',
                    'severity' => 'info',
                    'source' => 'system',
                    'subject_type' => 'subject',
                    'subject_id' => '1',
                    'version' => 3,
                    'context' => [],
                    'before' => ['city' => 'Lima', 'roles' => [['id' => 1, 'name' => 'admin']]],
                    'after' => ['city' => 'Arequipa', 'roles' => [['id' => 1, 'name' => 'owner']]],
                    'changes' => [
                        ['path' => '/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
                        ['path' => '/roles/0/name', 'op' => 'replace', 'old' => 'admin', 'new' => 'owner'],
                    ],
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => '729aaf38a9811bb46f582c262d4e4e2ab4a7509dc9cb4989d8356e8508402402',
                    'occurred_at' => '2026-08-26 10:00:00.000000',
                ],
                '{"actor_id":null,"actor_type":null,"affected_rows":null,"after":{"city":"Arequipa","roles":[{"id":1,"name":"owner"}]},"audit_type":"model","before":{"city":"Lima","roles":[{"id":1,"name":"admin"}]},"changes":[{"new":"Arequipa","old":"Lima","op":"replace","path":"/city"},{"new":"owner","old":"admin","op":"replace","path":"/roles/0/name"}],"context":[],"criteria":null,"encryption":null,"event":"updated","id":"01JGOLDEN000000000000000E5","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-26 10:00:00.000000","request_id":null,"severity":"info","source":"system","source_audit_id":null,"span_id":null,"subject_id":"1","subject_type":"subject","tenant_id":null,"trace_id":null,"transaction_id":null,"version":3}',
                '6b42c17df4bb627e6bf237d873bd420b9fcf463c7fa035a3b09fd5012413086e',
            ],
            'an entry that resolved its own context' => [
                [
                    'id' => '01JGOLDEN000000000000000F6',
                    'stream' => 'tenant:acme',
                    'sequence' => 5,
                    'audit_type' => 'model',
                    'event' => 'updated',
                    'severity' => 'info',
                    'source' => 'api',
                    'subject_type' => 'subject',
                    'subject_id' => '500',
                    'actor_type' => 'user',
                    'actor_id' => '1',
                    'impersonator_type' => 'user',
                    'impersonator_id' => '7',
                    'tenant_id' => 'acme',
                    'request_id' => '01JREQUEST00000000000000R1',
                    'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
                    'span_id' => '00f067aa0ba902b7',
                    'context' => [
                        'environment' => 'production',
                        'hostname' => 'node-1',
                        'ip' => '203.0.113.7',
                        'method' => 'PATCH',
                        'route' => 'invoices.update',
                        'session_id' => 'ULoRcHqYZzGeMjTqFvHDvLPuAeJhTsYP',
                        'url' => 'https://example.test/api/invoices/500',
                        'user_agent' => 'Sentinel/1.0',
                    ],
                    'changes' => [['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'sent']],
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => '729aaf38a9811bb46f582c262d4e4e2ab4a7509dc9cb4989d8356e8508402402',
                    'occurred_at' => '2026-08-26 10:00:00.000000',
                ],
                '{"actor_id":"1","actor_type":"user","affected_rows":null,"after":null,"audit_type":"model","before":null,"changes":[{"new":"sent","old":"draft","op":"replace","path":"/status"}],"context":{"environment":"production","hostname":"node-1","ip":"203.0.113.7","method":"PATCH","route":"invoices.update","session_id":"ULoRcHqYZzGeMjTqFvHDvLPuAeJhTsYP","url":"https://example.test/api/invoices/500","user_agent":"Sentinel/1.0"},"criteria":null,"encryption":null,"event":"updated","id":"01JGOLDEN000000000000000F6","impersonator_id":"7","impersonator_type":"user","metadata":null,"occurred_at":"2026-08-26 10:00:00.000000","request_id":"01JREQUEST00000000000000R1","severity":"info","source":"api","source_audit_id":null,"span_id":"00f067aa0ba902b7","subject_id":"500","subject_type":"subject","tenant_id":"acme","trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","transaction_id":null,"version":null}',
                '2946fa2e8f0235870977c3dfd9533f8c895c1470fedecf30f4427f88236ab176',
            ],
        ];
    }
}
