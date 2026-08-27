<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Snapshot;

use BackedEnum;
use DateTimeInterface;
use ElPandaPe\Sentinel\Exceptions\SnapshotException;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use UnitEnum;

final readonly class SnapshotBuilder
{
    // Frozen with payload_version 1: a snapshot that round trips has to keep its precision.
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Config $config) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function build(Model $model, array $attributes): array
    {
        $replica = $model->newInstance();
        $replica->setRawAttributes($attributes, true);

        $snapshot = [];

        foreach ($this->keys($model, $attributes) as $key) {
            $snapshot[$key] = $this->normalize($key, $replica->getAttributeValue($key));
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function keys(Model $model, array $attributes): array
    {
        $policy = AuditPolicy::of($model);
        $keys = array_keys($attributes);

        if ($policy->included !== []) {
            return array_values(array_intersect($keys, $policy->included));
        }

        $dropped = $this->config->snapshotsIncludeHidden()
            ? $policy->excluded
            : [...$policy->excluded, ...$model->getHidden()];

        return array_values(array_diff($keys, $dropped));
    }

    private function normalize(string $attribute, mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            $value instanceof Arrayable => $this->each($attribute, $value->toArray()),
            $value instanceof JsonSerializable => $this->normalize($attribute, $value->jsonSerialize()),
            is_array($value) => $this->each($attribute, $value),
            $value instanceof Stringable => (string) $value,
            default => throw SnapshotException::unsupportedType($attribute, $value),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function each(string $attribute, array $value): array
    {
        $normalized = array_map(fn (mixed $item): mixed => $this->normalize($attribute, $item), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
