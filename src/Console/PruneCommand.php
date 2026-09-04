<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Console\Concerns\ReadsOptions;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Console\Concerns\WalksStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Retention\Pruner;
use ElPandaPe\Sentinel\Retention\PruneReport;
use ElPandaPe\Sentinel\Retention\Pruning;
use Illuminate\Console\Command;
use Throwable;

/**
 * Applies the retention policies, and says what it would do before it does anything.
 *
 * Archive is the default, because the action that loses nothing is the one an operator should get
 * for forgetting a flag. It was deliberately not a default while `delete` was the only action there
 * was: a default that meant "remove" then and "write it out first" now would have changed what a
 * scheduled command did without the schedule changing.
 *
 * Three exit codes, the same three sentinel:verify uses and for the same reason: a chain that does
 * not add up and a command that could not run are different facts. A run that removed nothing exits
 * zero — it is the ordinary outcome of a schedule, and the report says which of the four reasons it
 * was rather than leaving an operator to guess.
 */
final class PruneCommand extends Command
{
    use ReadsOptions;
    use Translates;
    use WalksStreams;

    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:prune
        {--action=archive : What to do with a range retention has released: archive writes it out and proves it before removing it, delete removes it without writing it anywhere}
        {--stream= : Prune this stream instead of every one}
        {--batch= : How many entries one statement removes; defaults to the configured size}
        {--dry-run : Report what a run would remove, and remove nothing}';

    public function handle(Pruner $pruner, Ledger $ledger): int
    {
        $declared = $this->option('action');
        $action = PruneAction::tryFrom(is_string($declared) ? $declared : '');

        if (! $action instanceof PruneAction) {
            $this->warn($this->translated('unknown_action', [
                'action' => is_string($declared) ? $declared : '',
                'accepted' => implode(', ', array_map(static fn (PruneAction $one): string => $one->value, PruneAction::cases())),
            ]));

            return self::INVALID;
        }

        $dryRun = $this->option('dry-run') === true;

        try {
            $report = $this->walk($pruner, $ledger, $action, $dryRun);
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        $this->render($report);

        $break = $report->firstBreak();

        if ($break instanceof VerificationResult) {
            $this->error($break->message());

            return self::FAILURE;
        }

        $this->info($this->summary($report, $action, $dryRun));

        return self::SUCCESS;
    }

    private function walk(Pruner $pruner, Ledger $ledger, PruneAction $action, bool $dryRun): PruneReport
    {
        $stream = $this->option('stream');
        $batch = $this->option('batch');
        $slice = is_string($batch) && $batch !== '' ? (int) $batch : null;
        $streams = is_string($stream) && $stream !== '' ? [$stream] : $this->streams($ledger);
        $now = CarbonImmutable::now();

        return new PruneReport(array_map(
            static fn (string $name): Pruning => $pruner->prune($pruner->plan($name, $now), $action, $dryRun, $slice),
            $streams,
        ));
    }

    private function render(PruneReport $report): void
    {
        $this->table(
            [
                $this->translated('columns.stream'),
                $this->translated('columns.ranges'),
                $this->translated('columns.entries'),
                $this->translated('columns.rate'),
                $this->translated('columns.note'),
            ],
            array_map(fn (Pruning $pruning): array => [
                $pruning->frontier->stream,
                $pruning->windows,
                $pruning->removed->audits,
                $this->rate($pruning),
                $pruning->frontier->message() === '' ? '—' : $pruning->frontier->message(),
            ], $report->streams),
        );
    }

    private function rate(Pruning $pruning): string
    {
        $rate = $pruning->rate();

        return $rate === null ? '—' : $this->translated('per_second', ['rate' => number_format($rate)]);
    }

    private function summary(PruneReport $report, PruneAction $action, bool $dryRun): string
    {
        if ($report->entries() === 0) {
            return $this->translated('nothing');
        }

        return $this->translated($this->summaryKey($action, $dryRun), [
            'entries' => $report->entries(),
            'windows' => $report->windows(),
            'streams' => count($report->streams),
        ]);
    }

    private function summaryKey(PruneAction $action, bool $dryRun): string
    {
        return match (true) {
            $action === PruneAction::Archive && $dryRun => 'planned_archive',
            $action === PruneAction::Archive => 'archived',
            $dryRun => 'planned',
            default => 'removed',
        };
    }

    /**
     * @param  array<string, int|string>  $replace
     */
}
