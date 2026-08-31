<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Enums\ArchiveCodec;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

/**
 * Where a batch goes, derived from what it holds and from nothing else.
 *
 * A pure function of the range on purpose: a run interrupted after the object landed and before the
 * manifest heard about it rewrites the same key on the next pass, rather than leaving an orphan
 * nobody can find. Entries are immutable, so the same range produces the same bytes.
 *
 * The stream is slugged and then disambiguated by a digest of itself. Slugging alone is not enough
 * in either direction: a stream name is any string up to sixty-four characters and a closure
 * resolver can return one full of slashes or dots, so the slug is a traversal guard; and two names
 * that slug alike would otherwise share a directory.
 */
final readonly class BatchPath
{
    private const int MAX_LENGTH = 512;

    private const int SEQUENCE_WIDTH = 20;

    public static function for(string $root, string $stream, int $from, int $to, ?ArchiveCodec $codec): string
    {
        $path = sprintf(
            '%s/%s-%s/%s-%s.ndjson%s',
            $root,
            self::slug($stream),
            substr(hash('sha256', $stream), 0, 8),
            str_pad((string) $from, self::SEQUENCE_WIDTH, '0', STR_PAD_LEFT),
            str_pad((string) $to, self::SEQUENCE_WIDTH, '0', STR_PAD_LEFT),
            $codec?->extension() ?? '',
        );

        return strlen($path) <= self::MAX_LENGTH
            ? $path
            : throw ConfigurationException::archivePathTooLong($path);
    }

    /**
     * Zero-padded to the width of the column the range ends live in, so a listing of the disk sorts
     * the way the chain does.
     */
    private static function slug(string $stream): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $stream);
    }
}
