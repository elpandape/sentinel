<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

use ElPandaPe\Sentinel\Exceptions\ImportException;
use Illuminate\Database\DatabaseManager;

/**
 * Whether a table is the one it was taken for, asked before a single row is read.
 *
 * The schemas of both source packages have moved between majors and an application is free to move
 * them further. A mapping that is quietly one column out does not fail: it imports, and what lands
 * in the trail says something other than what it means. So the question is asked first and the
 * answer to a shape nobody recognises is a refusal, the same one the archive gives a container
 * format it cannot read.
 *
 * Names only. Laravel's schema builder answers with the column list and nothing about types, and
 * asking for more would mean reaching past it into an engine-specific dialect for a check that a
 * missing column already fails.
 */
final readonly class Shape
{
    public function __construct(private DatabaseManager $databases) {}

    public function verify(Origin $origin, string $table, ?string $connection): void
    {
        $schema = $this->databases->connection($connection)->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw ImportException::absentTable($table, $connection ?? 'default');
        }

        /** @var list<string> $present */
        $present = $schema->getColumnListing($table);

        $missing = array_values(array_filter(
            $origin->columns(),
            static fn (string $column): bool => ! in_array($column, $present, true),
        ));

        if ($missing !== []) {
            throw ImportException::unrecognisedShape($table, $origin->name(), $missing);
        }
    }
}
