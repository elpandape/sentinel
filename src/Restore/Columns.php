<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use Illuminate\Database\Eloquent\Model;

/**
 * What columns a table still has. A restoration has to ask, because an entry may hold a field a
 * later migration dropped and putting that back would fail on the save rather than be refused with
 * a reason — but the answer is the same for every restoration of the same table in the same
 * process, and asking the schema is a round trip to `information_schema` on two of the three
 * engines.
 *
 * Scoped, like the rest of the package's per-request state. A migration that runs inside a request
 * and then restores in the same one is not a case worth holding a stale answer for; a request that
 * restores a thousand rows is.
 */
final class Columns
{
    /**
     * @var array<string, list<string>>
     */
    private array $known = [];

    /**
     * @return list<string>
     */
    public function of(Model $subject): array
    {
        $connection = $subject->getConnection();
        $table = $subject->getTable();
        $key = $connection->getName().'|'.$table;

        return $this->known[$key] ??= $connection->getSchemaBuilder()->getColumnListing($table);
    }
}
