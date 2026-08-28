<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent named by a column that is not its primary key, which is what forces the read: the entry
 * has to name the parent the way every other entry names its subject.
 */
final class Bulletin extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    protected array $auditParents = ['editor' => 'edited'];

    /**
     * @return BelongsTo<Author, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_code', 'code');
    }

    public function getTable(): string
    {
        return 'fixture_articles';
    }

    public function usesTimestamps(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return [];
    }
}
