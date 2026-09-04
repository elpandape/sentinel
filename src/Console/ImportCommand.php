<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\ReadsOptions;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Import\Importer;
use ElPandaPe\Sentinel\Import\Origin;
use ElPandaPe\Sentinel\Import\Origins\Altek;
use ElPandaPe\Sentinel\Import\Origins\OwenIt;
use ElPandaPe\Sentinel\Import\Report;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings a trail in from another package.
 *
 * The chain it builds starts here. Everything the other package recorded before this command ran
 * has no link, because it never had one — nobody hashed those rows as they were written, and
 * fabricating a previous hash backwards would be manufacturing the one thing this engine exists to
 * make impossible to manufacture. What the import gives is a trail from the point of import that
 * verifies, sitting on top of a history that is carried over honestly and says where it came from.
 *
 * A dry run is the documented way to start and the flag suppresses the writing, never the reading:
 * it maps every row, applies the pipeline that would refuse one, and reports exactly what a real
 * run would do. That is why it can exit non-zero.
 */
final class ImportCommand extends Command
{
    use ReadsOptions;
    use Translates;

    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:import
        {--from= : Which package to read from: owenit or altek}
        {--table= : Where the source history lives, if the application moved it}
        {--connection= : The connection the source lives on}
        {--actor= : The prefix the source gives its two actor columns}
        {--size=500 : How many source rows to read at a time}
        {--after= : Skip every source row up to and including this key}
        {--dry-run : Report what an import would do, and import nothing}';

    public function handle(Importer $importer, Config $config): int
    {
        $origin = $this->origin($config);

        if (! $origin instanceof Origin) {
            $this->warn($this->translated('unknown_origin', [
                'origin' => $this->text('from') ?? '',
                'accepted' => implode(', ', [OwenIt::NAME, Altek::NAME]),
            ]));

            return self::INVALID;
        }

        try {
            $report = $importer->import(
                $origin,
                $this->text('table') ?? $origin->table(),
                $this->text('connection'),
                $this->number('size') ?? 500,
                $this->text('after'),
                (bool) $this->option('dry-run'),
            );
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        return $this->report($report);
    }

    private function origin(Config $config): ?Origin
    {
        $actor = $this->text('actor');

        return match ($this->text('from')) {
            OwenIt::NAME => new OwenIt($config, $actor ?? OwenIt::ACTOR),
            Altek::NAME => new Altek($config, $actor ?? Altek::ACTOR),
            default => null,
        };
    }

    /**
     * What became of every row, and then what it means. A row that did not come across is a finding
     * about the source rather than a failure of the run, so it is reported and exits one: the run
     * happened, and something did not come with it.
     */
    private function report(Report $report): int
    {
        $this->render($report);

        $outcome = $report->rehearsed ? 'would' : 'imported';

        $this->info($this->translated($outcome, [
            'written' => $report->written,
            'read' => $report->read,
        ]));

        if ($report->lastRow !== null) {
            $this->line($this->translated('resume', ['row' => $report->lastRow]));
        }

        if ($report->unreadable() === 0 && $report->discarded === 0) {
            return self::SUCCESS;
        }

        $this->error($this->translated('incomplete', [
            'unreadable' => $report->unreadable(),
            'discarded' => $report->discarded,
        ]));

        return self::FAILURE;
    }

    private function render(Report $report): void
    {
        $this->table(
            [
                $this->translated('columns.outcome'),
                $this->translated('columns.rows'),
            ],
            [
                [$this->translated('outcomes.read'), $report->read],
                [$this->translated('outcomes.written'), $report->written],
                [$this->translated('outcomes.repeated'), $report->repeated],
                [$this->translated('outcomes.discarded'), $report->discarded],
                [$this->translated('outcomes.unreadable'), $report->unreadable()],
            ],
        );

        foreach ($report->unmappable as $reason => $rows) {
            $this->line($this->translated('unreadable_rows', ['rows' => $rows, 'reason' => $reason]));
        }
    }
}
