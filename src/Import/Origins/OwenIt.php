<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import\Origins;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Mapping;
use ElPandaPe\Sentinel\Import\Origin;
use ElPandaPe\Sentinel\Import\Row;
use ElPandaPe\Sentinel\Support\Config;

/**
 * A trail written by `owen-it/laravel-auditing`, read as entries of this one.
 *
 * The shape below is the one that package has carried unchanged since its v10 and still carries in
 * v14, so one mapping covers five majors. What moves between installations is not the columns but
 * their names: the two actor columns take their prefix from the source application's own
 * configuration, so it arrives here rather than being written into the class.
 *
 * Three things that package does not record, and this does not invent:
 *
 * Nobody on whose behalf. There is no impersonation anywhere in it — an open proposal, and nothing
 * shipped — so `impersonator_type` and `impersonator_id` stay empty.
 *
 * The whole record, on an update. It writes what Eloquent called dirty and nothing else, so an
 * imported update portrays the fields that moved rather than the record. A create and a delete do
 * carry everything. The restore guard does not tell them apart on purpose: a rule that has to be
 * reasoned about per event is one somebody will reason about wrongly.
 *
 * An attribute whose value was an array. That package drops those before it writes, unless the
 * application turned a setting on, so the gap is already in the rows and closing it here would mean
 * inventing what was never captured.
 */
final readonly class OwenIt implements Origin
{
    public const string NAME = 'owenit';

    public const string TABLE = 'audits';

    public const string ACTOR = 'user';

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
            'event',
            'auditable_type',
            'auditable_id',
            'old_values',
            'new_values',
            'url',
            'ip_address',
            'user_agent',
            'tags',
            'created_at',
        ];
    }

    public function map(Row $row): Mapping
    {
        $key = $row->text('id');
        $occurred = $row->instant('created_at');
        $subject = $row->text('auditable_type');

        if ($key === null) {
            return Mapping::refused('the row has no key of its own to be identified by');
        }

        if (! $occurred instanceof DateTimeImmutable) {
            return Mapping::refused('the row does not say when it happened, and an invented instant is worse than no entry');
        }

        if ($subject === null || $row->text('auditable_id') === null) {
            return Mapping::refused('the row does not say what it is about');
        }

        $event = $row->text('event') ?? 'updated';
        $before = $row->json('old_values');
        $after = $row->json('new_values');

        return Mapping::of(new AuditData(
            audit_type: 'model',
            event: $event,
            severity: $this->config->defaultSeverity($event),
            occurred_at: $occurred,
            source: Source::Import,
            subject_type: $subject,
            subject_id: $row->text('auditable_id'),
            actor_type: $row->text($this->actor.'_type'),
            actor_id: $row->text($this->actor.'_id'),
            context: $this->context($row),
            before: $before,
            after: $after,
            changes: $before === null && $after === null ? null : Diff::between($before ?? [], $after ?? [])->toArray(),
            metadata: ['import' => ['origin' => self::NAME, 'row' => $key]],
            capture_id: Identity::of(self::NAME, $key),
            tags: $this->tags($row),
        ));
    }

    /**
     * The three things it knows about where the request came from, under the names this package
     * gives them. The rest of what §9 defines has no answer in the source and is left absent: an
     * empty key would read as "nothing came from there" rather than "nobody wrote it down".
     *
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
     * Labels, which that package keeps as one string joined by commas and never escapes. A label
     * with a comma in it was already two labels before this read it, and splitting it here is the
     * only thing that can be done with what is on the row.
     *
     * @return list<string>
     */
    private function tags(Row $row): array
    {
        $tags = $row->text('tags');

        return $tags === null
            ? []
            : array_values(array_filter(array_map(trim(...), explode(',', $tags)), static fn (string $tag): bool => $tag !== ''));
    }
}
