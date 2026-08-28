<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a business operation was called, when it ran and how much it wrote. Its key is the
 * transaction_id every entry of the operation carries, so a header and its entries find each
 * other without a join table.
 *
 * It is mutable on purpose, unlike Audit: the row is written when the scope opens — an operation
 * that died halfway has to be findable — and completed when it closes. Nothing here is hashed and
 * nothing here is evidence; the facts are the entries, and the chain covers those.
 *
 * @property string $id
 * @property string $name
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $tenant_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $audits_count
 * @property array<string, mixed>|null $metadata
 * @property-read Model|null $actor
 */
class AuditTransaction extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * An operation that has just opened has written nothing, and it says so before the database
     * gets a chance to: a header read back from the row it inserted must not differ from the one
     * that inserted it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['audits_count' => 0];

    /**
     * The entries this operation wrote, in the order the ledger settled them. They are found by
     * the header's own key: transaction_id is that key on the entry side.
     *
     * @return HasMany<Audit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class, 'transaction_id')->orderBy('id');
    }

    public function getTable(): string
    {
        return $this->config()->table('transactions');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    // The columns are datetime(6): without the microseconds Eloquent would truncate what the
    // schema declares, and two operations of the same millisecond would order arbitrarily.
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * @return list<string>
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
            'audits_count' => 'integer',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
