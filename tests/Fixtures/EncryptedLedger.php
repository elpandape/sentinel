<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * An entry whose values went through the whole pipeline: one field encrypted, one masked,
 * one digested. The ciphertext is frozen literally and never regenerated — encryption is
 * not deterministic, so a test that re-encrypted in order to compare would only be
 * asserting what it had just produced.
 *
 * The key that wrote it is deliberately absent from these fixtures. Verification has to
 * pass without it, which is the whole reason the hash covers the ciphertext.
 */
final class EncryptedLedger
{
    public const string PLAINTEXT = '12345678Z';

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function entries(): array
    {
        return [
            'an entry protected by all three mechanisms' => [
                [
                    'id' => '01JGOLDEN000000000000000G7',
                    'stream' => 'tenant:acme',
                    'sequence' => 7,
                    'audit_type' => 'model',
                    'event' => 'updated',
                    'severity' => 'warning',
                    'source' => 'http',
                    'subject_type' => 'user',
                    'subject_id' => '7',
                    'actor_type' => 'user',
                    'actor_id' => '1',
                    'tenant_id' => 'acme',
                    'version' => 2,
                    'context' => [
                        'ip' => 'c****7',
                        'url' => '/profile',
                    ],
                    'before' => [
                        'dni' => 'eyJpdiI6IjFwL01YRi9obkNrUzN0MkUiLCJ2YWx1ZSI6IkNDbVJCR2Exai9ERWdzM3FxZWNKYWc9PSIsIm1hYyI6IiIsInRhZyI6Ii9UWndRUnhGbDZraG84OFB6MEtEK3c9PSJ9',
                        'email' => 'c****s@e****e.c****m',
                        'card' => '1af4a34d9df62a595b9b62ca9a9886fe75b3260396d0ffa14377136b089b1d92',
                    ],
                    'after' => [
                        'dni' => 'eyJpdiI6IjFINUhhL0NTTnkxRTNZTEciLCJ2YWx1ZSI6IjBUcnlZbnBtV1dhYXYyeENVeDRnN2c9PSIsIm1hYyI6IiIsInRhZyI6IjNDaW5lZjNPOEdVQkdFdFM0VTA5TUE9PSJ9',
                        'email' => 'g****e@e****e.c****m',
                        'card' => 'c13a28f3e57f0afa3ca787719c3082ff60bfa6cf9853259b197ba1df27d70b92',
                    ],
                    'changes' => [
                        [
                            'path' => '/dni',
                            'op' => 'replace',
                            'old' => 'eyJpdiI6InRtQ2hYOUs4ZEtqUGlmeE0iLCJ2YWx1ZSI6IlhGRmNnL01BL05kQWh5N0JyVEFkY1E9PSIsIm1hYyI6IiIsInRhZyI6Ind3TFBKM0JIN1JoS21SWGw1aVdaenc9PSJ9',
                            'new' => 'eyJpdiI6InRtRGEzdisvbUlrWVZXYk8iLCJ2YWx1ZSI6IllXTEUrZEp5d0NvUkJpWjd4clJLcFE9PSIsIm1hYyI6IiIsInRhZyI6IitsQXJoZUFFSWhuemJ4UFhiY1dra2c9PSJ9',
                        ],
                    ],
                    'metadata' => null,
                    'encryption' => [
                        'fields' => [
                            'dni',
                        ],
                        'key_id' => 'default',
                    ],
                    'criteria' => null,
                    'affected_rows' => null,
                    'source_audit_id' => null,
                    'payload_version' => 1,
                    'algorithm' => 'sha256',
                    'previous_hash' => '752d2a1b0ededff42112fde4b1429d2d5e11ffe85ff664a41fe0e9730d5adbfe',
                    'occurred_at' => '2026-08-27 12:00:00.000000',
                ],
                '{"actor_id":"1","actor_type":"user","affected_rows":null,"after":{"card":"c13a28f3e57f0afa3ca787719c3082ff60bfa6cf9853259b197ba1df27d70b92","dni":"eyJpdiI6IjFINUhhL0NTTnkxRTNZTEciLCJ2YWx1ZSI6IjBUcnlZbnBtV1dhYXYyeENVeDRnN2c9PSIsIm1hYyI6IiIsInRhZyI6IjNDaW5lZjNPOEdVQkdFdFM0VTA5TUE9PSJ9","email":"g****e@e****e.c****m"},"audit_type":"model","before":{"card":"1af4a34d9df62a595b9b62ca9a9886fe75b3260396d0ffa14377136b089b1d92","dni":"eyJpdiI6IjFwL01YRi9obkNrUzN0MkUiLCJ2YWx1ZSI6IkNDbVJCR2Exai9ERWdzM3FxZWNKYWc9PSIsIm1hYyI6IiIsInRhZyI6Ii9UWndRUnhGbDZraG84OFB6MEtEK3c9PSJ9","email":"c****s@e****e.c****m"},"changes":[{"new":"eyJpdiI6InRtRGEzdisvbUlrWVZXYk8iLCJ2YWx1ZSI6IllXTEUrZEp5d0NvUkJpWjd4clJLcFE9PSIsIm1hYyI6IiIsInRhZyI6IitsQXJoZUFFSWhuemJ4UFhiY1dra2c9PSJ9","old":"eyJpdiI6InRtQ2hYOUs4ZEtqUGlmeE0iLCJ2YWx1ZSI6IlhGRmNnL01BL05kQWh5N0JyVEFkY1E9PSIsIm1hYyI6IiIsInRhZyI6Ind3TFBKM0JIN1JoS21SWGw1aVdaenc9PSJ9","op":"replace","path":"/dni"}],"context":{"ip":"c****7","url":"/profile"},"criteria":null,"encryption":{"fields":["dni"],"key_id":"default"},"event":"updated","id":"01JGOLDEN000000000000000G7","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-27 12:00:00.000000","request_id":null,"severity":"warning","source":"http","source_audit_id":null,"span_id":null,"subject_id":"7","subject_type":"user","tenant_id":"acme","trace_id":null,"transaction_id":null,"version":2}',
                '375895bc95ef36b1826095b6b62e50da3cb44f21733629d360e3a79cac9fe229',
            ],
        ];
    }
}
