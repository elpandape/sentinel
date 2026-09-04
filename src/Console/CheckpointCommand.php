<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\ReadsOptions;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Console\Concerns\WalksStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Integrity\Checkpoints;
use Illuminate\Console\Command;
use Throwable;

/**
 * Anchors every complete window a stream still owes. It is what a scheduled task runs and what an
 * operator runs by hand, and it is the route the README recommends: emitting on a threshold puts
 * the cost of reading a window on whoever happened to write the entry that crossed it.
 *
 * The schedule itself belongs to the application. This package registers commands and nothing more;
 * one that puts itself on a scheduler is a surprise in someone else's application.
 *
 * On a stream with nothing left to anchor it emits nothing and says so, which is the ordinary
 * outcome of running it on a schedule and is not a failure.
 */
final class CheckpointCommand extends Command
{
    use ReadsOptions;
    use Translates;
    use WalksStreams;

    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:checkpoint
        {--stream= : Anchor this stream instead of every one}';

    public function handle(Checkpoints $checkpoints, Ledger $ledger): int
    {
        $stream = $this->option('stream');

        try {
            $streams = is_string($stream) && $stream !== '' ? [$stream] : $this->streams($ledger);
            $issued = [];

            foreach ($streams as $name) {
                $issued = [...$issued, ...$checkpoints->issue($name)];
            }
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        if ($issued === []) {
            $this->info($this->translated('none'));

            return self::SUCCESS;
        }

        $this->render($issued);
        $this->info($this->translated('anchored', ['count' => count($issued)]));

        return self::SUCCESS;
    }

    /**
     * @param  list<Checkpoint>  $issued
     */
    private function render(array $issued): void
    {
        $this->table(
            [
                $this->translated('columns.stream'),
                $this->translated('columns.from'),
                $this->translated('columns.to'),
                $this->translated('columns.root'),
            ],
            array_map(static fn (Checkpoint $anchor): array => [
                $anchor->stream,
                $anchor->from,
                $anchor->to,
                $anchor->rootHash,
            ], $issued),
        );
    }

    /**
     * @param  array<string, int|string>  $replace
     */
}
