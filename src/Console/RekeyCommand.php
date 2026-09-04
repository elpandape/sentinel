<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Security\Rekeyer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-encrypts a range of the trail under a new key.
 *
 * Rotation writes; it never rewrites. Each entry that carries protected fields gets a NEW entry
 * holding the same values under the new key, pointing back at the one it stands in for — and the
 * original keeps its hash, its link and its sequence, and keeps verifying for as long as its key
 * stays on the keyring.
 *
 * Which is what makes this the opposite of a redaction, and why no path of this command calls that
 * one: a tombstone destroys content, a rekey preserves it under a different lock.
 */
final class RekeyCommand extends Command
{
    use Translates;

    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:rekey
        {--key= : The key identifier to re-encrypt under; defaults to the current one}
        {--tenant= : Only this tenant}
        {--type= : Only this audit type}
        {--limit=500 : How many entries at most}
        {--dry-run : Say how many would be re-encrypted, and re-encrypt none}';

    public function handle(Rekeyer $rekeyer, AuditQuery $query): int
    {
        $key = $this->text('key');
        $entries = $this->narrowed($query)->get();

        if ($this->option('dry-run')) {
            $this->info($this->translated('would', ['entries' => $entries->count()]));

            return self::SUCCESS;
        }

        try {
            $rekeyed = $this->rotate($rekeyer, array_values($entries->all()), $key);
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        $this->info($this->translated('rekeyed', ['entries' => $rekeyed, 'read' => $entries->count()]));

        return self::SUCCESS;
    }

    /**
     * @param  list<Audit>  $entries
     */
    private function rotate(Rekeyer $rekeyer, array $entries, ?string $key): int
    {
        $rekeyed = 0;

        foreach ($entries as $entry) {
            if ($rekeyer->rekey($entry, $key) instanceof Audit) {
                $rekeyed++;
            }
        }

        return $rekeyed;
    }

    private function narrowed(AuditQuery $query): AuditQuery
    {
        $tenant = $this->text('tenant');
        $type = $this->text('type');
        $limit = $this->text('limit');

        if ($tenant !== null) {
            $query = $query->forTenant($tenant);
        }

        if ($type !== null) {
            $query = $query->whereType($type);
        }

        return $query->take($limit === null ? 500 : (int) $limit);
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
}
