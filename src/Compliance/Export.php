<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Compliance;

use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\Signers;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use JsonException;

/**
 * The trail, rendered for somebody who does not have the database.
 *
 * Every format carries the same manifest: how many entries, the digest of the body, and a signature
 * over that digest with the key the installation signs entries with. That is what makes the file
 * worth receiving — a recipient with the verifying half of the key can tell that the bytes are the
 * bytes that were exported, without being given access to anything.
 *
 * `csv` is lossy and says so. It flattens the nested columns into JSON strings inside cells, which is
 * fine for a person reading a spreadsheet and wrong for anything that would be read back in. `ndjson`
 * is the one that round-trips.
 *
 * A redacted entry exports as redacted: the serialized shape carries the redaction block, so what
 * leaves the building says the contents were destroyed rather than pretending they were empty.
 */
final readonly class Export
{
    public const array FORMATS = ['json', 'ndjson', 'csv'];

    /**
     * The columns a spreadsheet gets. Nested values are JSON inside the cell, which is the lossy part.
     */
    private const array CSV_COLUMNS = [
        'id', 'audit_type', 'event', 'severity', 'source', 'subject', 'actor', 'tenant_id',
        'version', 'changes', 'before', 'after', 'metadata', 'tags', 'occurred_at', 'created_at',
    ];

    public function __construct(
        private Hasher $hasher,
        private Signers $signers,
        private Config $config,
    ) {}

    /**
     * @param  AuditCollection<int, Audit>  $entries
     *
     * @throws JsonException
     */
    public function render(AuditCollection $entries, string $format): Exported
    {
        $body = match ($format) {
            'ndjson' => $this->ndjson($entries),
            'csv' => $this->csv($entries),
            default => $this->json($entries),
        };

        $algorithm = $this->config->integrityAlgorithm();
        $digest = $algorithm.':'.$this->hasher->digest($body, $algorithm);
        $signer = $this->signers->current();

        return new Exported($body, $format, $entries->count(), $digest, $signer->sign($digest), $signer->keyId());
    }

    /**
     * @param  AuditCollection<int, Audit>  $entries
     *
     * @throws JsonException
     */
    private function json(AuditCollection $entries): string
    {
        return json_encode(
            array_map(static fn (Audit $entry): array => $entry->toArray(), $entries->all()),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /**
     * @param  AuditCollection<int, Audit>  $entries
     *
     * @throws JsonException
     */
    private function ndjson(AuditCollection $entries): string
    {
        $lines = array_map(
            static fn (Audit $entry): string => json_encode($entry->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $entries->all(),
        );

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param  AuditCollection<int, Audit>  $entries
     *
     * @throws JsonException
     */
    private function csv(AuditCollection $entries): string
    {
        $rows = [implode(',', self::CSV_COLUMNS)];

        foreach ($entries as $entry) {
            $serialized = $entry->toArray();

            $rows[] = implode(',', array_map(
                fn (string $column): string => $this->cell($serialized[$column] ?? null),
                self::CSV_COLUMNS,
            ));
        }

        return implode("\n", $rows)."\n";
    }

    /**
     * @throws JsonException
     */
    private function cell(mixed $value): string
    {
        $rendered = match (true) {
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return '"'.str_replace('"', '""', (string) $rendered).'"';
    }
}
