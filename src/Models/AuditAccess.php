<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One read of the trail, projected so it can be queried by actor and by date.
 *
 * It is not the evidence and does not pretend to be: nothing here is hashed, chained or signed, and
 * a row of it can be edited by anyone who can write the table. What makes a read provable is the
 * entry it points at, which is an ordinary audit entry with audit_type 'access' — chained, hashed and
 * signed like every other. This row is the index; that entry is the proof.
 *
 * @property string $id
 * @property string $audit_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $tenant_id
 * @property array<string, mixed> $query
 * @property int $results
 * @property array<string, mixed> $context
 * @property CarbonImmutable $created_at
 */
class AuditAccess extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public function getTable(): string
    {
        return $this->config()->table('access_log');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    // The column is datetime(6): without the microseconds Eloquent would truncate what the schema declares.
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * The entry that proves this row, when it is still there. A read whose entry was pruned leaves a
     * row that is still a truthful record of a read — which is why this is a lookup and never a
     * foreign key.
     */
    public function audit(): ?Audit
    {
        return Audit::query()->find($this->audit_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query' => 'array',
            'context' => 'array',
            'results' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
