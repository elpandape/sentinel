<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use Illuminate\Database\Connection;
use stdClass;

/**
 * Serializes the emitters of one stream before they read where its anchors end. Contiguity is
 * derived here and not left to the schema: a unique index over the start of a range rejects two
 * anchors that begin in the same place and sees neither a hole between two of them nor an overlap
 * between two that begin in different ones. So the range is derived under a lock and the index is
 * the last arbiter of the race, which is the division of labour the chain's own gate already uses.
 *
 * The lock names the anchors of a stream and never the stream itself. Emission is kept outside the
 * sealing transaction so it does not hold the writers, and taking the lock the writers take would
 * put it straight back inside.
 */
final readonly class CheckpointGate
{
    private const string ADVISORY_LOCK = 'select pg_advisory_xact_lock(hashtext(?))';

    private const string SCOPE = 'checkpoint:';

    public function __construct(private Connection $connection, private string $table) {}

    public function tail(string $stream): AnchorTail
    {
        // PostgreSQL takes no lock on a row that is not there yet, so a stream with no anchors is
        // locked by name — the same hole StreamGate has to cover for a chain with no entries.
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement(self::ADVISORY_LOCK, [self::SCOPE.$stream]);
        }

        $row = $this->connection->table($this->table)
            ->select(['sequence_to', 'root_hash'])
            ->where('stream', $stream)
            ->orderByDesc('sequence_to')
            ->limit(1)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->from($row) : AnchorTail::empty();
    }

    private function from(stdClass $row): AnchorTail
    {
        return new AnchorTail(
            is_numeric($row->sequence_to) ? (int) $row->sequence_to : 0,
            is_string($row->root_hash) ? $row->root_hash : null,
        );
    }
}
