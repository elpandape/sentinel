<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The row a retired range lives in: which stream, which sequences, how many entries, and — once
 * there is somewhere cold to put them — where they went and what proves the file is the file.
 *
 * It is the opposite of AuditCheckpoint in the one way that matters. An anchor can be thrown away
 * and derived again from the entries; this cannot, because the entries it accounts for are the ones
 * that are no longer there. Losing this table loses the map, not a shortcut.
 *
 * What it is still not is evidence. Nothing here is hashed or signed, and a row of it says only
 * that somebody retired a range — which is why the verification never takes one on its own word.
 *
 * @property string $id
 * @property string $stream
 * @property int $sequence_from
 * @property int $sequence_to
 * @property int $records
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $checksum
 * @property string|null $compressed
 * @property CarbonImmutable $created_at
 */
class AuditArchive extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public function getTable(): string
    {
        return $this->config()->table('archives');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    // The column is datetime(6), like every other clock this package writes.
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
     * The range ends are read through the casts and never off a raw column: MySQL and PostgreSQL
     * hand a bigint back through PDO as a string and SQLite as an int, and the arithmetic that
     * crosses an absence has to mean the same thing on all three.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence_from' => 'integer',
            'sequence_to' => 'integer',
            'records' => 'integer',
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
