<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

/**
 * An identity derived from what it stands for rather than minted: the row an import read, the
 * entry and key a rotation goes to.
 *
 * It is what makes running the same pass twice cost nothing. Every write in this package already goes
 * through one deduplication: a capture identifier the ledger is asked about before a batch is
 * settled, and a unique index that has the last word. A minted identifier is different on every
 * run and buys none of that; one derived from the source and the row's own key is the same on
 * every run, so the second pass finds its own work already done and writes nothing.
 *
 * The source name is part of the digest and not a prefix, because two packages are free to number
 * their rows from one and a row's key alone would collide across them. It is what keeps the two
 * callers apart too: a rotation names itself, so it cannot land on an identity an import derived.
 *
 * It lives here rather than in Import for the reason Reference lives here rather than in Query:
 * deriving an identity from what it stands for is something any writer that wants a second pass to
 * cost nothing does, not something an importer does. It arrived with the importer and stayed there
 * one version too long — long enough for the call graph to say that rotating keys depends on
 * importing from another package, which was never true.
 *
 * What comes out is a valid ULID by shape — twenty-six Crockford characters, the first of them
 * inside the three bits a ULID leaves for it — and is not one by meaning: there is no instant
 * encoded in the front of it. Nothing in the package reads one out. The column holds twenty-six
 * characters and every path that touches it compares it for equality, which is the only thing an
 * identity derived this way is for.
 */
final class DerivedIdentity
{
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int LENGTH = 26;

    public static function of(string $source, string $row): string
    {
        $bits = '';

        foreach (str_split(substr(hash('sha256', $source."\x1f".$row, true), 0, 16)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $identity = '';

        foreach (str_split(str_pad($bits, self::LENGTH * 5, '0', STR_PAD_LEFT), 5) as $chunk) {
            $identity .= self::symbol($chunk);
        }

        return $identity;
    }

    /**
     * The one character five bits spell. Thirty-two symbols and thirty-two values, which is the
     * whole of why the alphabet is the size it is.
     */
    private static function symbol(string $bits): string
    {
        return substr(self::ALPHABET, (int) bindec($bits), 1);
    }
}
