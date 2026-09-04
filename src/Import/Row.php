<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

/**
 * One row of somebody else's table, read as something this package can use.
 *
 * Every column of a foreign history arrives untyped: a driver hands back strings where the schema
 * said integers, JSON as text, and nulls wherever the other package allowed one. Both origins need
 * the same coercions, so they live here once instead of twice, and every one of them answers null
 * rather than guessing — a column that is not there and a column that is empty are the same fact to
 * an importer, which is that there is nothing to carry over.
 */
final readonly class Row
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public static function of(object $row): self
    {
        /** @var array<string, mixed> $values */
        $values = get_object_vars($row);

        return new self($values);
    }

    public function text(string $column): ?string
    {
        $value = $this->values[$column] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function integer(string $column): ?int
    {
        $value = $this->values[$column] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A column the other package encoded as JSON. Anything that is not an object at the top level
     * comes back null: a list or a scalar in a column meant to hold an attribute map is a column
     * this importer does not understand, and understanding it wrongly is the failure that matters.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $column): ?array
    {
        $value = $this->values[$column] ?? null;

        if (is_array($value)) {
            return $this->map($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->map($decoded) : null;
    }

    /**
     * When the row says it happened. Both packages made their timestamps nullable, so a row that
     * does not say is a row this cannot place on a timeline — and an entry whose instant was made
     * up is worse than an entry that was not written.
     */
    public function instant(string $column): ?DateTimeImmutable
    {
        $value = $this->text($column);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateTimeImmutable();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function map(array $decoded): ?array
    {
        $mapped = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $mapped[$key] = $value;
        }

        return $mapped;
    }
}
