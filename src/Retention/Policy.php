<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

/**
 * One line of the retention map, resolved. The key is what the operator wrote and the target is
 * what the column actually holds, which are not the same string the moment an application declares
 * a morph map: 'model:App\Models\User' is written by a person and 'user' is what lands in
 * subject_type.
 *
 * A subject policy is more specific than a type policy, and that is the whole of the precedence
 * rule — decided here rather than at each of the two places that ask.
 */
final readonly class Policy
{
    private function __construct(
        public string $key,
        public string $target,
        public bool $bySubject,
        public Duration $duration,
    ) {}

    public static function subject(string $key, string $target, Duration $duration): self
    {
        return new self($key, $target, true, $duration);
    }

    public static function type(string $key, string $target, Duration $duration): self
    {
        return new self($key, $target, false, $duration);
    }

    public function matches(?string $subjectType, string $auditType): bool
    {
        return $this->bySubject
            ? $subjectType === $this->target
            : $auditType === $this->target;
    }
}
