<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The row an anchor lives in. While the range it covers is still in sentinel_audits it is a
 * shortcut and nothing more: the root is derivable from the entries again, and a stream with no
 * anchors verifies the same way, only by reading all of it.
 *
 * Once the range is retired it is the evidence, and the only evidence. Verification refuses an
 * absence unless the manifest accounts for it and the anchors reach past it, and the manifest is
 * unsigned — so an anchor lost after a purge is a gap that can no longer be told from a deletion.
 *
 * That is why it is not guarded the way Audit is, and why that is not the same as being disposable.
 * Nothing here is hashed into a chain, because an anchor stands outside the one it attests; what
 * stands in for that is the signature, and one that no longer resolves says so on the next
 * verification.
 *
 * @property string $id
 * @property string $stream
 * @property int $sequence_from
 * @property int $sequence_to
 * @property string $root_hash
 * @property string $algorithm
 * @property string|null $signature
 * @property string|null $key_id
 * @property CarbonImmutable $created_at
 */
class AuditCheckpoint extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public function getTable(): string
    {
        return $this->config()->table('checkpoints');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    // The column is datetime(6): without the microseconds Eloquent would truncate what the schema
    // declares, and two anchors of the same millisecond would order arbitrarily.
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
            'sequence_from' => 'integer',
            'sequence_to' => 'integer',
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
