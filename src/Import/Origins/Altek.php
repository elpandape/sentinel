<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import\Origins;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Mapping;
use ElPandaPe\Sentinel\Import\Origin;
use ElPandaPe\Sentinel\Import\Row;
use ElPandaPe\Sentinel\Support\Config;

/**
 * A trail written by `altek/accountant`, read as entries of this one.
 *
 * It is not the other origin with different column names, and finding that out is what the shape
 * check is for. That package writes a full snapshot of the record on every row and, beside it, a
 * bare list of the attribute NAMES that were dirty. There are no earlier values anywhere in it.
 *
 * So an imported row's `after` is real and complete, and its `before` is empty — not because this
 * chose not to carry one, but because nobody wrote one. Reconstructing it would mean pairing every
 * row with the previous row of the same record and calling the result a fact somebody recorded,
 * which is the one thing an audit engine may not do. What `changes` says instead is that these
 * attributes took these values, and nothing about what they held before, because that is the whole
 * of what the source knows.
 *
 * Its `signature` is a digest of the row over itself and links to nothing: altering one row leaves
 * the next one's signature intact. It is kept as data and never as this package's own signature,
 * which means something else entirely.
 */
final readonly class Altek implements Origin
{
    public const string NAME = 'altek';

    public const string TABLE = 'ledgers';

    public const string ACTOR = 'user';

    private const string CREATED = 'created';

    public function __construct(private Config $config, private string $actor = self::ACTOR) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function table(): string
    {
        return self::TABLE;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return [
            'id',
            $this->actor.'_type',
            $this->actor.'_id',
            'context',
            'event',
            'recordable_type',
            'recordable_id',
            'properties',
            'modified',
            'pivot',
            'extra',
            'url',
            'ip_address',
            'user_agent',
            'signature',
            'created_at',
        ];
    }

    public function map(Row $row): Mapping
    {
        $key = $row->text('id');
        $occurred = $row->instant('created_at');
        $subject = $row->text('recordable_type');

        if ($key === null) {
            return Mapping::refused('the row has no key of its own to be identified by');
        }

        if (! $occurred instanceof DateTimeImmutable) {
            return Mapping::refused('the row does not say when it happened, and an invented instant is worse than no entry');
        }

        if ($subject === null || $row->text('recordable_id') === null) {
            return Mapping::refused('the row does not say what it is about');
        }

        $event = $row->text('event') ?? 'updated';
        $properties = $row->json('properties');

        return Mapping::of(new AuditData(
            audit_type: 'model',
            event: $event,
            severity: $this->config->defaultSeverity($event),
            occurred_at: $occurred,
            source: Source::Import,
            subject_type: $subject,
            subject_id: $row->text('recordable_id'),
            actor_type: $row->text($this->actor.'_type'),
            actor_id: $row->text($this->actor.'_id'),
            context: $this->context($row),
            after: $properties,
            changes: $this->changes($row, $event, $properties),
            metadata: ['import' => $this->kept($row, $key)],
            capture_id: Identity::of(self::NAME, $key),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Row $row): array
    {
        return array_filter([
            'url' => $row->text('url'),
            'ip' => $row->text('ip_address'),
            'user_agent' => $row->text('user_agent'),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * The attributes the source called modified, with the value they ended up holding and nothing
     * about the one they held before.
     *
     * The diff engine already has the word for this. A change carries `oldKnown`, which exists so
     * that a caller who only had a patch can say the earlier value is missing instead of letting a
     * null stand in for it — and that is exactly what a row of this source is. Every change here is
     * built with it false, so `changes` comes out with no `old` key at all rather than with one
     * asserting something nobody wrote.
     *
     * The operation mirrors the event, because the source's own word for the column is `modified`:
     * on a creation those attributes took their first value, and on anything else they replaced one
     * whose identity is the thing that was not recorded.
     *
     * @param  array<string, mixed>|null  $properties
     * @return list<array{path: string, op: string, old?: mixed, new: mixed}>|null
     */
    private function changes(Row $row, string $event, ?array $properties): ?array
    {
        $modified = $row->names('modified');

        if ($properties === null || $modified === null) {
            return null;
        }

        $operation = $event === self::CREATED ? 'add' : 'replace';

        return Diff::fromChanges(array_map(
            static fn (string $name): Change => new Change('/'.$name, $operation, new: $properties[$name], oldKnown: false),
            array_values(array_filter($modified, static fn (string $name): bool => array_key_exists($name, $properties))),
        ))->toArray();
    }

    /**
     * What the source wrote that this package has no column for. It is kept verbatim under one key
     * so it is obvious where it came from, and reinterpreting any of it would be inventing.
     *
     * The execution context is a bitmask of that package's own — one for a test, two for the
     * command line, four for the web — and it stays the number it is. It is worth keeping because
     * of what its absence means there: a context the source application left out of its own
     * configuration recorded nothing at all, silently, and a gap in an imported trail is a
     * question somebody will eventually ask.
     *
     * @return array<string, mixed>
     */
    private function kept(Row $row, string $key): array
    {
        return array_filter([
            'origin' => self::NAME,
            'row' => $key,
            'context' => $row->integer('context'),
            'signature' => $row->text('signature'),
            'extra' => $row->json('extra'),
            'pivot' => $row->json('pivot'),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
