<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Integrity\Content;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Writes a batch and then proves it, in this order and never another: build the lines, compress,
 * digest what is about to be written, write, READ BACK, digest again and compare, rebuild every
 * entry out of the bytes that came back and rehash it against the hash it carries sealed. Only a
 * batch that survives all of that is handed to the caller, and only then may a row be removed.
 *
 * The rehash is not belt and braces. JsonCanonicalizer sorts keys and dispatches on array_is_list(),
 * so a PHP map whose keys are exactly {0..n-1} in the wrong order goes out as an object and comes
 * back as a list — a shape the database round trip preserves and this one does not. Without this
 * check that entry is archived, purged, and found to be unrestorable years later, when there is
 * nothing left to do about it.
 *
 * The read-back is also the only proof the Filesystem contract offers that the bytes landed at all.
 */
final readonly class BatchWriter
{
    public function __construct(
        private Factory $disks,
        private Canonicalizer $canonicalizer,
        private Hasher $hasher,
        private Content $content,
        private Audit $model,
        private Config $config,
    ) {}

    /**
     * @param  list<Audit>  $entries
     * @param  list<AuditTransaction>  $operations
     */
    public function write(string $stream, int $from, int $to, array $entries, array $operations, string $writtenAt): ArchiveBatch
    {
        $codec = $this->config->archiveCodec();
        $name = $this->config->archiveDisk();
        $path = BatchPath::for($this->config->archivePath(), $stream, $from, $to, $codec);
        $disk = $this->disks->disk($name);

        $lines = [Line::header($stream, $from, $to, count($entries), $writtenAt)];

        foreach ($entries as $entry) {
            $lines[] = Line::entry($entry);
        }

        foreach ($operations as $operation) {
            $lines[] = Line::operation($operation);
        }

        $bytes = $codec?->compress($this->encode($lines)) ?? $this->encode($lines);
        $algorithm = $this->config->integrityAlgorithm();
        $checksum = $algorithm.':'.$this->hasher->digest($bytes, $algorithm);

        if ($disk->put($path, $bytes) === false) {
            throw ArchiveException::refused($name, $path);
        }

        $written = $disk->get($path);

        if (! is_string($written)) {
            throw ArchiveException::unreadable($name, $path);
        }

        if (! hash_equals($checksum, $algorithm.':'.$this->hasher->digest($written, $algorithm))) {
            throw ArchiveException::corrupt($path);
        }

        $this->prove($path, $codec?->decompress($written) ?? $written);

        return new ArchiveBatch($stream, $from, $to, count($entries), $name, $path, $checksum, $codec?->value);
    }

    /**
     * Every entry line, rebuilt out of what came back and hashed again. An entry that no longer
     * reproduces what it was sealed with is refused here, where the cost is a batch nobody keeps.
     *
     * What it is entitled to reproduce depends on whether it was redacted: a tombstone reproduces its
     * second hash and never its first, so asking only for the first would make every redacted range
     * unarchivable — which is exactly the refusal v0.19.3 had to publish and this version lifts.
     */
    private function prove(string $path, string $body): void
    {
        foreach (Batch::entriesIn($body) as $decoded) {
            $rebuilt = Line::toAudit($decoded, $this->model);

            if (! $this->content->holds($rebuilt)) {
                throw ArchiveException::unverifiable($path, $rebuilt->sequence);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function encode(array $lines): string
    {
        return implode("\n", array_map($this->canonicalizer->canonicalize(...), $lines))."\n";
    }
}
