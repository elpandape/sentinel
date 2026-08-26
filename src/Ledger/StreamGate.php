<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Illuminate\Database\Connection;
use stdClass;

/**
 * Serializes the writers of one stream before they read its tail. The hash covers the
 * sequence and the previous hash, so the tail has to be read before the row is built:
 * no INSERT can compute its own link.
 */
final readonly class StreamGate
{
    private const string ADVISORY_LOCK = 'select pg_advisory_xact_lock(hashtext(?))';

    public function __construct(private Connection $connection, private string $table) {}

    public function tail(string $stream): StreamTail
    {
        // PostgreSQL takes no lock on a row that is not there yet, so the stream is locked by name.
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement(self::ADVISORY_LOCK, [$stream]);
        }

        // In InnoDB the gap lock covers the first write of a stream. SQLite ignores the clause.
        $row = $this->connection->table($this->table)
            ->select(['sequence', 'hash'])
            ->where('stream', $stream)
            ->orderByDesc('sequence')
            ->limit(1)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->from($row) : StreamTail::empty();
    }

    private function from(stdClass $row): StreamTail
    {
        return new StreamTail(
            is_numeric($row->sequence) ? (int) $row->sequence : 0,
            is_string($row->hash) ? $row->hash : null,
        );
    }
}
