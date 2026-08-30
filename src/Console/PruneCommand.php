<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Retention\Pruner;
use ElPandaPe\Sentinel\Retention\PruneReport;
use ElPandaPe\Sentinel\Retention\Pruning;
use Illuminate\Console\Command;
use Override;
use Throwable;

/**
 * Applies the retention policies, and says what it would do before it does anything.
 *
 * The action has no default. This release can only remove, and a default that means "remove" today
 * and "write it out first" tomorrow would change what a scheduled command does without the schedule
 * changing. Naming it is one word an operator writes once.
 *
 * Three exit codes, the same three sentinel:verify uses and for the same reason: a chain that does
 * not add up and a command that could not run are different facts. A run that removed nothing exits
 * zero — it is the ordinary outcome of a schedule, and the report says which of the four reasons it
 * was rather than leaving an operator to guess.
 */
final class PruneCommand extends Command
{
    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:prune
        {--action= : What to do with a range retention has released. Required, and delete is the only one this release has}
        {--stream= : Prune this stream instead of every one}
        {--dry-run : Report what a run would remove, and remove nothing}';

    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

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

        $this->info($this->summary($report, $dryRun));

        return self::SUCCESS;
    }

    private function walk(Pruner $pruner, Ledger $ledger, PruneAction $action, bool $dryRun): PruneReport
    {
        $stream = $this->option('stream');
        $streams = is_string($stream) && $stream !== '' ? [$stream] : $this->named($ledger);
        $now = CarbonImmutable::now();

        return new PruneReport(array_map(
            static fn (string $name): Pruning => $pruner->prune($pruner->plan($name, $now), $action, $dryRun),
            $streams,
        ));
    }

    /**
     * @return list<string>
     */
    private function named(Ledger $ledger): array
    {
        return $ledger instanceof EnumeratesStreams
            ? $ledger->streams()
            : throw QueryException::cannotEnumerateStreams($ledger::class);
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

    private function summary(PruneReport $report, bool $dryRun): string
    {
        if ($report->entries() === 0) {
            return $this->translated('nothing');
        }

        return $this->translated($dryRun ? 'planned' : 'removed', [
            'entries' => $report->entries(),
            'windows' => $report->windows(),
            'streams' => count($report->streams),
        ]);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function translated(string $key, array $replace = []): string
    {
        $line = trans('sentinel::sentinel.commands.prune.'.$key, $replace);

        return is_string($line) ? $line : '';
    }
}
