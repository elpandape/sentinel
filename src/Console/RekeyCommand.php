<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\NarrowsTheTrail;
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
    use NarrowsTheTrail;
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
        {--after= : Resume behind this entry id, as reported by the pass before}
        {--dry-run : Say how many would be re-encrypted, and re-encrypt none}';

    public function handle(Rekeyer $rekeyer, AuditQuery $query): int
    {
        $key = $this->text('key');
        $entries = $this->resumed($query)->get();

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

        $last = $entries->last();

        if ($last instanceof Audit) {
            $this->info($this->translated('resume', ['audit' => $last->id]));
        }

        return self::SUCCESS;
    }

    /**
     * The narrowing, plus where the last pass stopped. Without it a pass reads the same oldest
     * entries every time: rotating them is now free, but the trail behind them is never reached.
     */
    private function resumed(AuditQuery $query): AuditQuery
    {
        $after = $this->text('after');
        $narrowed = $this->narrowed($query);

        return $after === null ? $narrowed : $narrowed->after($after);
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
}
