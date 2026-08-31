<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Enums\BatchLine;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * One line of a batch, both ways.
 *
 * An entry line holds the entry's own columns and nothing else: the twenty-seven of
 * canonical(core), the eight the chain seals around them, the five the row needs to exist again —
 * and `tags`, which the Ledger contract demands travel with an appended entry in as many words.
 * The rule is one sentence and one assertion: a line's key set is an entry's column set plus tags.
 *
 * It is deliberately not getAttributes(), whose JSON columns come back as whatever text the engine
 * stored, and not toArray(), which leaves out encryption, renders changes through the diff and
 * stamps the clocks in another format. Either would produce a line that cannot reproduce its own
 * hash on the way back.
 */
final readonly class Line
{
    public const int FORMAT = 1;

    /**
     * The eight columns the chain seals around the payload, and the five the row needs to exist
     * again. Together with CanonicalPayload::COLUMNS they are the whole of sentinel_audits, which is
     * what the key-set test asserts — so a column added to that table later is a loud failure here
     * rather than a silent loss on the way out.
     *
     * @var list<string>
     */
    public const array SEALED = [
        'stream',
        'sequence',
        'payload_version',
        'algorithm',
        'previous_hash',
        'hash',
        'signature',
        'signature_key_id',
    ];

    /**
     * @var list<string>
     */
    public const array KEPT = [
        'capture_id',
        'created_at',
        'redacted_at',
        'redaction_reason',
        'redacted_hash',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function header(string $stream, int $from, int $to, int $records, string $writtenAt): array
    {
        return [
            'kind' => BatchLine::Header->value,
            'format' => self::FORMAT,
            'stream' => $stream,
            'sequence_from' => $from,
            'sequence_to' => $to,
            'records' => $records,
            'written_at' => $writtenAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(Audit $audit): array
    {
        $line = ['kind' => BatchLine::Entry->value];

        foreach ([...CanonicalPayload::COLUMNS, ...self::SEALED, ...self::KEPT] as $column) {
            $line[$column] = CanonicalPayload::normalize($audit->getAttribute($column));
        }

        $line['tags'] = $audit->relationLoaded('tags')
            ? $audit->tags->map(static fn (AuditTag $tag): string => $tag->tag)->values()->all()
            : [];

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    public static function operation(AuditTransaction $header): array
    {
        $line = ['kind' => BatchLine::Operation->value];

        foreach (array_keys($header->getAttributes()) as $column) {
            $line[$column] = CanonicalPayload::normalize($header->getAttribute($column));
        }

        return $line;
    }

    /**
     * The entry a line describes, built on the model the ledger uses. Everything but the columns is
     * dropped before the fill: Audit guards nothing and has no mutator for `tags`, so a `kind` key
     * or a labels array left among the attributes would have the insert name a column that does not
     * exist.
     *
     * @param  array<string, mixed>  $line
     */
    public static function toAudit(array $line, Audit $model): Audit
    {
        $audit = $model->newInstance();

        $audit->forceFill(array_intersect_key(
            $line,
            array_flip([...CanonicalPayload::COLUMNS, ...self::SEALED, ...self::KEPT]),
        ));

        $audit->setRelation('tags', new Collection(array_map(
            static fn (string $tag): AuditTag => new AuditTag(['audit_id' => $audit->id, 'tag' => $tag]),
            self::labels($line),
        )));

        $audit->exists = true;
        $audit->syncOriginal();

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<string>
     */
    private static function labels(array $line): array
    {
        $tags = $line['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
    }
}
