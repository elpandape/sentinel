<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditCheckpoint;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Emits and reads the anchors of a stream.
 *
 * A window is a fixed number of entries, not whatever happened to be pending: with the second rule
 * the two ends of a range would depend on when the emission ran, and the root of one history would
 * stop being reproducible. The trailing incomplete window is not anchored, and a verification walks
 * it entry by entry, which is what it would have done for the whole stream anyway.
 *
 * The range is read before anything is written and the count has to fill the window exactly. That
 * covers the ordinary case — the entries are not there yet — and the one that matters, a hole
 * inside the range: folding over a short range would produce a root that the same range would not
 * reproduce once the missing entry was back.
 */
final readonly class Checkpoints
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Audit $audits,
        private AuditCheckpoint $anchors,
        private Fold $fold,
        private Signers $signers,
        private Config $config,
    ) {}

    /**
     * Every complete window the stream still owes, anchored in order. Each one is its own
     * transaction: an emission interrupted halfway leaves anchors that are contiguous as far as
     * they go, which is the only state the next run can carry on from.
     *
     * @return list<Checkpoint>
     */
    public function issue(string $stream): array
    {
        $issued = [];

        while (($anchor = $this->next($stream)) instanceof Checkpoint) {
            $issued[] = $anchor;
        }

        return $issued;
    }

    /**
     * @return list<Checkpoint>
     */
    public function of(string $stream): array
    {
        $anchors = [];

        foreach ($this->anchors->newQuery()->where('stream', $stream)->orderBy('sequence_from')->cursor() as $row) {
            $anchors[] = Checkpoint::of($row);
        }

        return $anchors;
    }

    /**
     * The root the range folds to now, out of the hashes its entries carry now. Null is not a
     * failure and is never reported as one: it means this build cannot recompute the root at all —
     * the entries are no longer all there, or the anchor names a construction it does not know —
     * and saying so is the difference between "not checked" and "checked and wrong".
     */
    public function refold(Checkpoint $anchor, ?string $previous): ?string
    {
        $digest = Fold::digestOf($anchor->algorithm);

        if ($digest === null) {
            return null;
        }

        $hashes = $this->hashes($anchor->stream, $anchor->from, $anchor->to);

        return count($hashes) === $anchor->length()
            ? $this->fold->root($anchor->stream, $anchor->from, $anchor->to, $previous, $digest, $hashes)
            : null;
    }

    /**
     * The last sequence the anchors of a stream reach, and zero for a stream nobody anchored. One
     * seek on the index the anchors already keep.
     *
     * It says how far they reach and never that they are contiguous up to there — contiguity is
     * what verifyAnchors() proves by walking them, and re-proving it here would be paying for it
     * twice to answer a smaller question.
     */
    public function reach(string $stream): int
    {
        $anchored = $this->anchors->newQuery()->where('stream', $stream)->max('sequence_to');

        return is_numeric($anchored) ? (int) $anchored : 0;
    }

    public function last(string $stream): ?Checkpoint
    {
        $row = $this->anchors->newQuery()
            ->where('stream', $stream)
            ->orderByDesc('sequence_to')
            ->first();

        return $row instanceof AuditCheckpoint ? Checkpoint::of($row) : null;
    }

    /**
     * The unique index is the final arbiter, so a loser of the race reads where the anchors end
     * again and takes the window after the one it lost.
     */
    private function next(string $stream): ?Checkpoint
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->attempt($stream);
            } catch (UniqueConstraintViolationException $violation) {
                if (++$attempt >= self::MAX_ATTEMPTS) {
                    throw $violation;
                }
            }
        }
    }

    private function attempt(string $stream): ?Checkpoint
    {
        // Asked first without a lock and without a transaction, because on every write of a window
        // but the one that completes it the answer is no. Opening a transaction and taking the gate
        // to hear that would put the price of anchoring on every write instead of on one in every
        // window, and reading the partial window to count it would make that price grow with the
        // window — which is how a wider window ended up costing more per write than a narrow one.
        if (! $this->pending($stream)) {
            return null;
        }

        $connection = $this->audits->getConnection();

        return $connection->transaction(function () use ($connection, $stream): ?Checkpoint {
            $tail = new CheckpointGate($connection, $this->anchors->getTable())->tail($stream);

            $from = $tail->sequence + 1;
            $to = $tail->sequence + $this->config->checkpointsEvery();
            $hashes = $this->hashes($stream, $from, $to);

            return count($hashes) === $to - $from + 1
                ? Checkpoint::of($this->write($stream, $from, $to, $tail->root, $hashes))
                : null;
        });
    }

    /**
     * Whether the stream has an entry where the next window would end. One seek into the index the
     * chain already keeps, against one read of where the anchors end.
     */
    private function pending(string $stream): bool
    {
        $from = $this->reach($stream) + 1;

        return $this->audits->newQuery()
            ->where('stream', $stream)
            ->where('sequence', $from + $this->config->checkpointsEvery() - 1)
            ->exists();
    }

    /**
     * @param  list<string>  $hashes
     */
    private function write(string $stream, int $from, int $to, ?string $previous, array $hashes): AuditCheckpoint
    {
        $algorithm = $this->config->integrityAlgorithm();
        $root = $this->fold->root($stream, $from, $to, $previous, $algorithm, $hashes);

        // The signature goes over the root the same way an entry's goes over its hash, and an empty
        // one leaves both columns null: an anchor nobody signed says so rather than claiming a
        // signature that attests to nothing.
        $signer = $this->signers->current();
        $signature = $signer->sign($root);

        $anchor = $this->anchors->newInstance();

        $anchor->forceFill([
            'stream' => $stream,
            'sequence_from' => $from,
            'sequence_to' => $to,
            'root_hash' => $root,
            'algorithm' => Fold::name($algorithm),
            'signature' => $signature === '' ? null : $signature,
            'key_id' => $signature === '' ? null : $signer->keyId(),
        ])->save();

        return $anchor;
    }

    /**
     * The hash of every entry of the range and nothing else. Folding neither canonicalizes nor
     * decrypts, so the read is two columns wide however heavy the entries are.
     *
     * @return list<string>
     */
    private function hashes(string $stream, int $from, int $to): array
    {
        /** @var list<string> $hashes */
        $hashes = $this->audits->newQuery()
            ->where('stream', $stream)
            ->whereBetween('sequence', [$from, $to])
            ->orderBy('sequence')
            ->pluck('hash')
            ->all();

        return $hashes;
    }
}
