<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

/**
 * A range of entries folded into one root, chained the way the entries themselves are: every step
 * digests the step before it, so a root cannot be reproduced from part of its range and the order
 * of the range is covered rather than assumed.
 *
 * It folds instead of building a tree because nothing on the way to v1.0.0 consumes an inclusion
 * proof. Git and RFC 5848 fold for that reason; the tree turns up exactly where a remote client
 * that does not trust the log asks for one record — CT, Trillian, immudb, QLDB — and every one of
 * them keeps a chain underneath it anyway. The construction travels in the prefix and in the
 * algorithm column, which is the door a merkle- one comes through without touching a row already
 * written.
 *
 * The prefix separates domains: the construction, the stream and both ends of the range go into it,
 * so a range of one cannot collide with the entry it contains and the same range over another
 * stream cannot land on the same root. It is why RFC 6962 §2.1 prefixes leaves and nodes — the
 * ambiguity is unfixable after the first anchor is written.
 *
 * The root of the previous anchor of the same stream goes in too, and it is what makes a chain of
 * anchors a chain: contiguous integers are not linkage, and without it whoever rewrites a range and
 * reissues its anchor produces a history that agrees with itself. With it, reissuing one anchor
 * obliges reissuing every anchor after it.
 */
final readonly class Fold
{
    private const string CONSTRUCTION = 'fold';

    public function __construct(private Hasher $hasher) {}

    public static function name(string $algorithm): string
    {
        return self::CONSTRUCTION.'-'.$algorithm;
    }

    /**
     * The digest inside a construction name, and null for a construction this one is not. It is the
     * door the algorithm column exists to leave open: an anchor written by something else is
     * reported as one this build cannot recompute, never as one that failed to.
     */
    public static function digestOf(string $name): ?string
    {
        return str_starts_with($name, self::CONSTRUCTION.'-')
            ? substr($name, strlen(self::CONSTRUCTION) + 1)
            : null;
    }

    /**
     * @param  iterable<string>  $hashes  the hash of every entry in the range, in ascending sequence
     */
    public function root(string $stream, int $from, int $to, ?string $previous, string $algorithm, iterable $hashes): string
    {
        $root = $this->hasher->digest(implode(Hasher::SEPARATOR, [
            self::name($algorithm),
            $stream,
            $from,
            $to,
            $previous ?? '',
        ]), $algorithm);

        foreach ($hashes as $hash) {
            $root = $this->hasher->digest($root.Hasher::SEPARATOR.$hash, $algorithm);
        }

        return $root;
    }
}
