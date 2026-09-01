<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

/**
 * What every ledger answers to. Three guarantees are deliberately weaker than a SQL driver
 * can offer, because a driver over a store without transactions cannot honour the strong
 * form and a contract nobody can implement is a contract that gets ignored:
 *
 *  - `writeMany()` is not atomic. It either returns everything that settled, or it throws
 *    having made a best effort to leave nothing behind. On a store with no rollback that
 *    effort is compensation, and compensation can be interrupted.
 *  - Nothing here promises that a read sees a write that just returned. `find()` and
 *    `stream()` may not show an entry `write()` handed back a moment ago.
 *  - Idempotency by `capture_id` belongs to the caller. A ledger cannot deduplicate what it
 *    cannot reliably look up, and the lookup is exactly the read with no promise attached. A batch
 *    that names the same capture twice is therefore a caller error, not something `writeMany()`
 *    resolves: the driver seals both and the unique index refuses them together. What the package
 *    hands a ledger never contains one, because `Dispatch\Settlement` drops the repeat first.
 *
 * What is guaranteed, and what a driver is judged on, is the chain: within one stream the
 * sequence is dense and monotonic, and every entry links to the one before it.
 */
interface Ledger
{
    public function write(AuditData $audit): Audit;

    /**
     * @param  list<AuditData>  $audits
     */
    public function writeMany(array $audits): AuditCollection;

    /**
     * Store an entry that is already sealed, exactly as it arrived: no sequence is assigned
     * and no hash is recomputed. It is how a secondary destination takes what the primary
     * sealed, because two ledgers assigning their own sequence produce two chains for one
     * fact — and how an archive or a replica takes an entry it did not write.
     *
     * "Exactly as it arrived" includes the labels it arrived carrying: an implementation that
     * stores the entry and drops them has stored something else. Labels travel on the entry as a
     * loaded relation, so an entry that arrives without that relation loaded is one that says
     * nothing about its labels, and storing none is the right answer there.
     *
     * It also moves whatever the driver uses to number a subject's next entry. A driver that
     * derives that from what it holds gets this for nothing; one that keeps a counter has to be
     * told, and one that is not told hands the next write a number the appended entry already has —
     * permanently, and with nothing to notice it by.
     */
    public function append(Audit $audit): Audit;

    public function find(string $id): ?Audit;

    public function query(AuditQuery $query): AuditCollection;

    public function stream(string $stream): LedgerStream;
}
