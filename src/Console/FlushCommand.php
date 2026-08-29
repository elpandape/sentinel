<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Console\Command;
use Override;
use Throwable;

/**
 * Settles everything the buffer is holding, on demand.
 *
 * It exists for the two cases the automatic triggers cannot reach: a buffer that stopped receiving
 * entries before either threshold was met, and a process that died holding some — the second being
 * the one the mode admits it can lose. Scheduling it is how an operator puts a ceiling on how long
 * anything waits, because nothing inside PHP is watching a clock between requests.
 *
 * Two of them running at once is safe and is the case worth stating: taking from the buffer is
 * atomic, so each gets entries and neither gets the other's, and the unique index on the capture
 * identifier settles the rest.
 */
final class FlushCommand extends Command
{
    protected $signature = 'sentinel:flush';

    #[Override]
    public function getDescription(): string
    {
        $line = trans('sentinel::sentinel.commands.flush.description');

        return is_string($line) ? $line : '';
    }

    /**
     * A failure is reported and given back as a non-zero exit, because a command that says nothing
     * and exits zero is one a cron will keep failing behind. Nothing is lost either way: what could
     * not be settled is back in the buffer, waiting for whatever triggers next.
     */
    public function handle(Config $config, Flusher $flusher): int
    {
        if ($config->mode() !== Mode::Buffered) {
            $this->warn((string) trans('sentinel::sentinel.commands.flush.not_buffered', ['mode' => $config->mode()->value]));

            return self::INVALID;
        }

        try {
            $settled = $flusher->flush();
        } catch (Throwable $failure) {
            $this->error((string) trans('sentinel::sentinel.commands.flush.failed', ['reason' => $failure->getMessage()]));

            return self::FAILURE;
        }

        $this->info((string) trans('sentinel::sentinel.commands.flush.settled', ['count' => $settled]));

        return self::SUCCESS;
    }
}
