<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

/**
 * What a mass operation was aimed at, written down as a structure rather than as the SQL it
 * compiled to. A statement with its values interpolated back into it is the one form of this the
 * entry must never hold: the point of recording the criteria is to say which rows were meant, and
 * a rendered `where email = 'ada@example.com'` says that by leaking it.
 *
 * The clauses it understands are named one by one, and everything else is opaque — its shape and
 * nothing more. That way round on purpose: a raw fragment or a subquery can carry literals no
 * declaration of the model reaches, and a clause a future framework release invents would
 * otherwise be written out whole by a serialiser that had never seen it.
 *
 * A column, an operator and a boolean are the query's own vocabulary and are always safe. A value
 * is the caller's, so it travels as a binding does — separate, and through the same redaction the
 * snapshots go through.
 */
final readonly class Criteria
{
    /**
     * @var list<string>
     */
    private const array COMPARISONS = ['Basic', 'Bitwise', 'NullSafeEquals', 'Date', 'Time', 'Day', 'Month', 'Year'];

    /**
     * @var list<string>
     */
    private const array SETS = ['In', 'NotIn', 'InRaw', 'NotInRaw'];

    /**
     * @var list<string>
     */
    private const array RANGES = ['between', 'betweenColumns'];

    /**
     * @var list<string>
     */
    private const array NULLITY = ['Null', 'NotNull'];

    public function __construct(private Config $config) {}

    /**
     * The shape of the operation: what it was aimed at, what it reached through, and the columns it
     * wrote whose value is not something to write down. The last of those lives here rather than in
     * `changes` because it is the same kind of fact as a raw fragment — a name, with the body left
     * out — and a change is not allowed to be silent about what it changed to.
     *
     * @param  list<string>  $opaque
     * @return array<string, mixed>
     */
    public function of(Builder $query, array $opaque = []): array
    {
        $criteria = ['wheres' => $this->wheres($query->wheres)];

        $joins = $this->joins($query->joins);

        if ($joins !== []) {
            $criteria['joins'] = $joins;
        }

        if ($opaque !== []) {
            $criteria['writes'] = $opaque;
        }

        return $criteria;
    }

    /**
     * An upsert has no criteria: it names the rows itself. What is worth recording is the shape of
     * what was sent — which columns, matched on what, how many rows — and none of that is a value.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     * @return array<string, mixed>
     */
    public function ofRows(array $columns, array $uniqueBy, array $update, int $rows): array
    {
        return ['columns' => $columns, 'unique_by' => $uniqueBy, 'update' => $update, 'rows' => $rows];
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @return list<array<string, mixed>>
     */
    private function wheres(array $wheres): array
    {
        return array_values(array_map($this->line(...), array_filter($wheres, is_array(...))));
    }

    /**
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function line(array $where): array
    {
        $type = is_string($where['type'] ?? null) ? $where['type'] : 'unknown';
        $line = ['type' => Str::snake($type), 'boolean' => $this->text($where, 'boolean') ?? 'and'];

        if ($type === 'Nested') {
            return [...$line, 'wheres' => $this->nested($where)];
        }

        if ($type === 'Column') {
            return $this->columns($line, $where);
        }

        $column = $this->text($where, 'column');

        if ($column === null) {
            return $line;
        }

        return match (true) {
            in_array($type, self::NULLITY, true) => [...$line, 'column' => $column],
            in_array($type, self::COMPARISONS, true) => $this->comparison($line, $where, $column),
            in_array($type, self::SETS, true) => $this->set($line, $where, $column),
            in_array($type, self::RANGES, true) => $this->range($line, $where, $column),
            $type === 'Like' => [...$line, 'column' => $column, 'not' => ($where['not'] ?? false) === true, ...$this->valued($where)],
            default => $line,
        };
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function columns(array $line, array $where): array
    {
        $first = $this->text($where, 'first');
        $second = $this->text($where, 'second');

        return $first === null || $second === null
            ? $line
            : [...$line, 'first' => $first, 'operator' => $this->text($where, 'operator') ?? '=', 'second' => $second];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function comparison(array $line, array $where, string $column): array
    {
        return [
            ...$line,
            'column' => $column,
            'operator' => $this->text($where, 'operator') ?? '=',
            ...$this->valued($where),
        ];
    }

    /**
     * A long set leaves its size and a sample of itself. Five thousand identifiers written out in
     * full would make the entry about the list rather than about the operation, and every one of
     * them still has to go through redaction on the way in.
     *
     * @param  array<string, mixed>  $line
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function set(array $line, array $where, string $column): array
    {
        $values = is_array($where['values'] ?? null) ? array_values($where['values']) : [];
        $written = $this->each(array_slice($values, 0, $this->config->massSample()));

        $line = [...$line, 'column' => $column, 'count' => count($values)];

        return $written === null ? $line : [...$line, 'values' => $written];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function range(array $line, array $where, string $column): array
    {
        $values = is_array($where['values'] ?? null) ? array_values($where['values']) : [];
        $written = $this->each($values);

        $line = [...$line, 'column' => $column, 'not' => ($where['not'] ?? false) === true];

        return $written === null ? $line : [...$line, 'values' => $written];
    }

    /**
     * @param  array<array-key, mixed>  $where
     * @return list<array<string, mixed>>
     */
    private function nested(array $where): array
    {
        $query = $where['query'] ?? null;

        return $query instanceof Builder ? $this->wheres($query->wheres) : [];
    }

    /**
     * The key is absent rather than filled with a placeholder when the value cannot be written
     * down. An entry that says which column was compared and stays silent about what it was
     * compared to is legible; one that says `"?"` invites a reader to wonder whether the caller
     * really did search for a question mark.
     *
     * Every clause that reaches this one carries a value — it is what makes it a comparison — so
     * the missing key is not a case, only a shape the array type cannot rule out.
     *
     * @param  array<array-key, mixed>  $where
     * @return array<string, mixed>
     */
    private function valued(array $where): array
    {
        $written = Literal::of($where['value'] ?? null);

        return $written === [] ? [] : ['value' => $written[0]];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>|null
     */
    private function each(array $values): ?array
    {
        $written = [];

        foreach ($values as $value) {
            $one = Literal::of($value);

            if ($one === []) {
                return null;
            }

            $written[] = $one[0];
        }

        return $written;
    }

    /**
     * @param  array<array-key, mixed>|null  $joins
     * @return list<array<string, string>>
     */
    private function joins(?array $joins): array
    {
        $tables = [];

        foreach ($joins ?? [] as $join) {
            if ($join instanceof JoinClause && is_string($join->table)) {
                $tables[] = ['type' => $join->type, 'table' => $join->table];
            }
        }

        return $tables;
    }

    /**
     * @param  array<array-key, mixed>  $where
     */
    private function text(array $where, string $key): ?string
    {
        $value = $where[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
