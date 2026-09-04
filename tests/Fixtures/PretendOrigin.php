<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Mapping;
use ElPandaPe\Sentinel\Import\Origin;
use ElPandaPe\Sentinel\Import\Row;

/**
 * An origin with a shape and almost nothing behind it. It is what lets the shape check and the
 * reading loop be tested for what they are — questions about a table — without a real package's
 * mapping standing in the way.
 */
final readonly class PretendOrigin implements Origin
{
    public const string TABLE = 'fixture_pretend_history';

    public function name(): string
    {
        return 'pretend';
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
        return ['id', 'event', 'subject_type', 'subject_id', 'created_at'];
    }

    public function map(Row $row): Mapping
    {
        $occurred = $row->instant('created_at');
        $key = $row->text('id');

        if (! $occurred instanceof DateTimeImmutable || $key === null) {
            return Mapping::refused('the row says neither when nor which it is');
        }

        return Mapping::of(new AuditData(
            audit_type: 'model',
            event: $row->text('event') ?? 'updated',
            severity: Severity::Info,
            occurred_at: $occurred,
            source: Source::Import,
            subject_type: $row->text('subject_type'),
            subject_id: $row->text('subject_id'),
            capture_id: Identity::of($this->name(), $key),
        ));
    }
}
