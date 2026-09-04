<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Partitions\Maintainer;
use ElPandaPe\Sentinel\Partitions\Maintenance;
use ElPandaPe\Sentinel\Retention\Duration;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Keeps a partitioned trail alive: the months in front of it exist before anything needs them, and
 * the ones behind it go once they are empty.
 *
 * Written to be scheduled, so it is idempotent and quiet. Running it twice in the same minute
 * creates nothing the second time — what should exist comes from the clock and what does exist
 * comes from the catalogue, and only the difference is issued.
 *
 * A table that is not partitioned exits zero and says so. That is the ordinary state of most
 * installations, and a maintenance command that fails on it is one nobody can put in a schedule.
 * The same three exit codes the other commands use, for the same reason: a refusal and a run that
 * could not happen are different facts. INVALID is a run that could not happen — an engine that
 * does not partition, an unreadable option, a thrown exception. FAILURE is a refusal: a partition
 * behind the cutoff that still holds entries under compliance mode, where a range may not leave
 * without a copy of it existing somewhere first.
 */
final class PartitionsCommand extends Command
{
    use Translates;

    /**
     * @var list<string>
     */
    public const array TABLES = ['audits', 'access_log'];

    /**
     * The option help stays in English, unlike everything the command prints. Options are built in
     * the constructor, before the package has loaded its translations, so a translated one would
     * resolve to its own key — and Laravel's own commands do not translate theirs either.
     */
    protected $signature = 'sentinel:partitions
        {--table=audits : Which configured table to maintain: audits, or access_log}
        {--ahead=3 : How many months beyond this one to have ready}
        {--retire= : Retire partitions whose month ended before this much time ago; without it nothing is retired}
        {--force : Retire a partition that still holds entries. Refused under compliance mode whatever this says}
        {--dry-run : Report what a run would do, and do nothing}';

    public function handle(Maintainer $maintainer, DatabaseManager $databases, Config $config): int
    {
        $name = $this->text('table') ?? 'audits';

        if (! in_array($name, self::TABLES, true)) {
            $this->warn($this->translated('unknown_table', ['table' => $name, 'accepted' => implode(', ', self::TABLES)]));

            return self::INVALID;
        }

        try {
            $maintenance = $maintainer->maintain(
                $databases->connection($config->connection()),
                $config->table($name),
                $this->number('ahead') ?? 3,
                $this->keep(),
                $this->option('force') === true,
                $this->option('dry-run') === true,
                CarbonImmutable::now(),
            );
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        return $this->report($maintenance);
    }

    private function report(Maintenance $maintenance): int
    {
        if (! $maintenance->divided) {
            $this->info($this->translated('undivided', ['table' => $maintenance->table]));

            return self::SUCCESS;
        }

        $this->render($maintenance);

        if ($maintenance->refused()) {
            $this->error($this->translated('refused', ['partitions' => count($maintenance->kept)]));

            return self::FAILURE;
        }

        $this->info($this->summary($maintenance));

        return self::SUCCESS;
    }

    private function render(Maintenance $maintenance): void
    {
        $rows = [
            ...array_map(fn (string $name): array => [$name, $this->translated('actions.created'), '—'], $maintenance->created),
            ...array_map(fn (string $name): array => [$name, $this->translated('actions.retired'), '—'], $maintenance->retired),
        ];

        foreach ($maintenance->kept as $name => $reason) {
            $rows[] = [$name, $this->translated('actions.kept'), $this->translated("reasons.{$reason}")];
        }

        if ($rows !== []) {
            $this->table(
                [
                    $this->translated('columns.partition'),
                    $this->translated('columns.action'),
                    $this->translated('columns.note'),
                ],
                $rows,
            );
        }
    }

    private function summary(Maintenance $maintenance): string
    {
        if ($maintenance->idle()) {
            return $this->translated('nothing', ['table' => $maintenance->table]);
        }

        return $this->translated($this->option('dry-run') === true ? 'planned' : 'maintained', [
            'created' => count($maintenance->created),
            'retired' => count($maintenance->retired),
            'table' => $maintenance->table,
        ]);
    }

    private function keep(): ?Duration
    {
        $declared = $this->text('retire');

        return $declared === null ? null : Duration::of('--retire', $declared);
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function number(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
}
