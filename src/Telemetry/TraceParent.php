<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry;

/**
 * A `traceparent` header, parsed under the rules of W3C Trace Context and not under a regular
 * expression that happens to fit the examples. Two of those rules are easy to get backwards and
 * both cost correctness: version `ff` is forbidden however well formed the rest is, and a version
 * above the one we know is not garbage — the spec requires reading its first three fields and
 * ignoring whatever it appended after them.
 *
 * A header that does not parse yields null, and null means the caller had no parent. It never
 * means a trace was invented to fill the hole.
 */
final readonly class TraceParent
{
    public const string VERSION = '00';

    private const string FORBIDDEN_VERSION = 'ff';

    private const int LENGTH = 55;

    private const string HEX = '/^[0-9a-f]+$/';

    private function __construct(
        public string $traceId,
        public string $spanId,
        public string $flags,
    ) {}

    public static function parse(string $header): ?self
    {
        if (strlen($header) < self::LENGTH) {
            return null;
        }

        $version = substr($header, 0, 2);

        if ($version === self::FORBIDDEN_VERSION || ! self::hex($version)) {
            return null;
        }

        $known = $version === self::VERSION;

        if ($known && strlen($header) !== self::LENGTH) {
            return null;
        }

        if (! $known && strlen($header) > self::LENGTH && $header[self::LENGTH] !== '-') {
            return null;
        }

        if ($header[2] !== '-' || $header[35] !== '-' || $header[52] !== '-') {
            return null;
        }

        $traceId = substr($header, 3, 32);
        $spanId = substr($header, 36, 16);
        $flags = substr($header, 53, 2);

        return self::identifiers($traceId, $spanId, $flags);
    }

    /**
     * A trace this process starts because nothing upstream started one. The sampled bit stays unset:
     * the package records audit entries, it does not record spans, so claiming the caller sampled
     * this trace would be claiming telemetry that nobody is going to find.
     */
    public static function root(): self
    {
        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), '00');
    }

    public static function of(string $traceId, string $spanId, string $flags = '00'): ?self
    {
        return self::identifiers($traceId, $spanId, $flags);
    }

    public function sampled(): bool
    {
        return ((int) hexdec($this->flags) & 1) === 1;
    }

    public function value(): string
    {
        return self::VERSION.'-'.$this->traceId.'-'.$this->spanId.'-'.$this->flags;
    }

    private static function identifiers(string $traceId, string $spanId, string $flags): ?self
    {
        $blank = str_repeat('0', 32);

        return self::hex($traceId) && self::hex($spanId) && self::hex($flags)
            && strlen($traceId) === 32 && strlen($spanId) === 16 && strlen($flags) === 2
            && $traceId !== $blank && $spanId !== substr($blank, 0, 16)
                ? new self($traceId, $spanId, $flags)
                : null;
    }

    private static function hex(string $value): bool
    {
        return preg_match(self::HEX, $value) === 1;
    }
}
