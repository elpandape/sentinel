<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class ImportException extends RuntimeException
{
    public static function absentTable(string $table, string $connection): self
    {
        return new self(
            "There is no table [{$table}] on the [{$connection}] connection. Name the table with "
            .'--table if the application moved it, or the connection with --connection.',
        );
    }

    /**
     * A table of the right name and the wrong shape is the case worth refusing loudest. Importing
     * from it would not fail — it would succeed, and put rows in the trail that mean something
     * other than what they say.
     *
     * @param  list<string>  $missing
     */
    public static function unrecognisedShape(string $table, string $origin, array $missing): self
    {
        return new self(
            "The table [{$table}] is not shaped like [{$origin}] history: it has no "
            .implode(', ', $missing).'. Importing from it would write entries that mean '
            .'something other than what they say, so nothing was read.',
        );
    }
}
