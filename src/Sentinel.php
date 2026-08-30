<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use Closure;
use ElPandaPe\Sentinel\Capture\PendingEvent;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Integrity\IntegrityReport;
use ElPandaPe\Sentinel\Integrity\StreamVerification;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Policies;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use ElPandaPe\Sentinel\Transitions\TransitionBuilder;
use ElPandaPe\Sentinel\Transitions\TransitionQuery;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class Sentinel
{
    private bool $paused = false;

    public function __construct(
        private readonly Config $config,
        private readonly ExecutionContext $context,
        private readonly Verifier $verifier,
        private readonly Policies $policies,
        private readonly Ledger $ledger,
        private readonly TransactionScope $transactions,
        private readonly Recorder $recorder,
    ) {}

    /**
     * The way in to the trail, and the only one: every read goes through the ledger contract
     * from here, so a driver over something that is not a table answers the same query, and
     * so the version that has to log who read what has one place to log it.
     */
    public function audits(): AuditQuery
    {
        return new AuditQuery($this->ledger);
    }

    /**
     * Everything that happened, in the order it happened. One query over one table: the trail
     * holds every kind of entry, so a timeline is the unnarrowed read with the clock of the fact
     * in front — not a merge of sources in PHP.
     */
    public function timeline(): AuditQuery
    {
        return $this->audits()->byOccurrence();
    }

    /**
     * One business operation, one identifier. Every entry captured inside takes it, and the
     * operation gets a header of its own with what it was called, who ran it and how much it
     * wrote — so a payment that touched an invoice, a payment record and two relations reads as
     * the one thing it was.
     *
     * It does not open a database transaction. Correlating and atomising are different
     * decisions, and combining them is the application's.
     */
    public function transaction(string $name, Closure $callback): mixed
    {
        return $this->isRecording() ? $this->transactions->run($name, $callback) : $callback();
    }

    /**
     * A fact the application states outright: an approval, a dispatch, a decision — something that
     * happened and that no model change describes. It settles through the same pipeline and the
     * same ledger as an update, and takes the identifier of whatever operation is running.
     *
     * The terminal is record(), and it is explicit: nothing is written until it is called.
     */
    public function event(string $name): PendingEvent
    {
        return new PendingEvent($name, $this, $this->recorder, $this->config);
    }

    /**
     * A record moving from one state to the next, as an entry of its own kind rather than an
     * update a reader has to recognise. The lifeline of a document — draft, pending, approved,
     * paid — becomes something the trail answers instead of something a diff has to be mined for.
     *
     * Sentinel does not move the record: it says that it moved. Executing the transition is the
     * application's, and giving it away would make an audit engine into a workflow engine.
     *
     * The terminal is record(), and it is explicit for the same reason event()'s is.
     */
    public function transition(Model $subject, bool|float|int|string|UnitEnum|null $from, bool|float|int|string|UnitEnum|null $to): TransitionBuilder
    {
        return new TransitionBuilder($subject, $from, $to, $this, $this->recorder, $this->config);
    }

    /**
     * The lifeline: every state a record has moved through, in the order it moved through them,
     * with how long it spent in each. One read of the trail, not a reconstruction from diffs.
     */
    public function transitions(): TransitionQuery
    {
        return new TransitionQuery($this->audits()->whereType(TransitionBuilder::AUDIT_TYPE));
    }

    /**
     * The last word on whether an entry settles. A policy that returns false discards it,
     * before the ledger has given it a sequence and therefore without leaving a gap.
     *
     * @param  Closure(AuditData): bool  $policy
     */
    public function filter(Closure $policy): void
    {
        $this->policies->add($policy);
    }

    /**
     * Reads every entry of the range and rehashes it. It is the only one of the three that proves
     * anything about what an entry says, and it is deliberately the one whose behaviour anchoring
     * does not change: an installation that switches anchors on does not quietly start verifying
     * less than it did the day before.
     */
    public function verifyIntegrity(string $stream, ?int $from = null, ?int $to = null): VerificationResult
    {
        return $this->verifier->verifyStream($stream, $from, $to);
    }

    /**
     * Walks the anchors instead of the history, and the tail no anchor covers yet. It costs rows in
     * proportion to history over window, and it proves what an anchor can prove: that the anchors
     * are a contiguous, signed chain and that the tail links. It reads no anchored entry, so it
     * reports those as anchored and never as intact.
     */
    public function verifyAnchors(string $stream): StreamVerification
    {
        return $this->verifier->verifyAnchors($stream);
    }

    /**
     * The same walk, folding every root again from the hashes the entries carry. It finds a hash
     * rewritten or reordered and names the entry, not just the range. It rehashes nothing, so an
     * entry edited without its hash column being touched still folds back — which is why this one
     * also reports a range it agrees with as anchored.
     */
    public function verifyRoots(string $stream): StreamVerification
    {
        return $this->verifier->verifyRoots($stream);
    }

    /**
     * Every chain the ledger holds, walked one at a time. Bounding by sequence is a question about
     * one stream and is asked of that stream: the same numbers mean different entries in each of
     * them, so a range across all of them would be a range across nothing.
     */
    public function verifyEverything(): IntegrityReport
    {
        return $this->verifier->verifyEverything();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return $this->config->enabled() && ! $this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function withoutAuditing(Closure $callback): mixed
    {
        $paused = $this->paused;

        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $paused;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context, Closure $callback): mixed
    {
        return $this->context->scope($context, $callback);
    }
}
