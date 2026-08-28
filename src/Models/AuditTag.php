<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

/**
 * One label on one entry. It carries no key of its own — the pair is the key — and no clock:
 * a label is classification, not a fact, and the entry it hangs off already knows when it
 * happened. It stays outside canonical(core), so labelling an old entry leaves its hash alone,
 * and equally leaves no trace.
 *
 * @property string $audit_id
 * @property string $tag
 */
class AuditTag extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'audit_id';

    protected $keyType = 'string';

    public function getTable(): string
    {
        return $this->config()->table('audit_tags');
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

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
