<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The entry as it will be written, offered to the application before it has identity. A listener
 * that returns false stops it, and this is the last place where stopping is free: past the ledger
 * the sequence has been assigned, and a discard there would leave a gap that verifyIntegrity()
 * reports as tampering. However it is stopped — here or by a stage — it leaves through
 * AuditDiscarded, which is the one door out.
 *
 * It is announced at the end of the pipeline and not before it, which is the same answer the last
 * stage gives for the same question: masking, hashing and encryption have run, so a listener holds
 * an entry with nothing in the clear that the ledger will not hold too. Before the pipeline it
 * would carry the plaintext of every declared field — including for the entries FilterUnchanged
 * drops, whose payload is never transformed at all — and a listener putting that on a queue is the
 * exact route the pipeline exists to close.
 *
 * What a listener may change is what the entry says about itself: its metadata, its labels, its
 * context. Not the subject — it names what the entry is about and which chain signs it, and an
 * entry moved onto another subject after its protections were resolved would be a different fact
 * under the same hash.
 */
final readonly class Auditing
{
    public const string REASON = 'cancelled';

    public function __construct(public AuditData $audit) {}
}
