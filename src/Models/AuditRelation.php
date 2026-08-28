<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of what a relation operation did, as a row you can index and filter. It carries no key
 * of its own and no clock: the entry it hangs off already knows when it happened, and the same
 * line is inside that entry's canonical changes, where the chain covers it.
 *
 * That is the whole point of the split. Altering a row here changes an index, not a fact, and
 * verifyIntegrity() is untouched by it — the README says so rather than leaving it to be assumed.
 *
 * @property string $audit_id
 * @property string $relation
 * @property string $operation
 * @property string|null $related_type
 * @property string|null $related_id
 * @property array<string, mixed>|null $pivot_before
 * @property array<string, mixed>|null $pivot_after
 */
class AuditRelation extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'audit_id';

    protected $keyType = 'string';

    public function getTable(): string
    {
        return $this->config()->table('audit_relations');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    /**
     * @return array<string>
     */
    public function getGuarded(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pivot_before' => 'array',
            'pivot_after' => 'array',
        ];
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
