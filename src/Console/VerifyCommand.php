<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Integrity\IntegrityReport;
use ElPandaPe\Sentinel\Integrity\Projections;
use ElPandaPe\Sentinel\Integrity\StreamVerification;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Integrity\Verifier;
use Illuminate\Console\Command;
use Override;
use Throwable;

/**
 * Walks the chain and says what it found, in a report a person reads and an exit code a cron acts
 * on. Three codes and not two: a broken chain and a command that could not run are different facts,
 * and a watchdog that cannot tell them apart will eventually treat one as the other.
 *
 * What is not a failure is as deliberate as what is. A trail nobody has signed exits zero, because
 * it is sound and saying otherwise would make the command useless on every installation that has
 * not switched signing on; the report says how many entries are unsigned so the operator sees it
 * without being alarmed by it.
 */
final class VerifyCommand extends Command
{
    /**
     * The option help stays in English, unlike everything the command prints. Options are built in
     * the constructor, before the package has loaded its translations, so a translated one would
     * resolve to its own key — and Laravel's own commands do not translate theirs either.
     */
    protected $signature = 'sentinel:verify
        {--stream= : Verify this stream instead of every one}
        {--from= : First sequence to verify; needs a stream}
        {--to= : Last sequence to verify; needs a stream}
        {--projections : Also check that the relation index still matches the entries}';

    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

    public function handle(Verifier $verifier, Projections $projections): int
    {
        $stream = $this->text('stream');
        $from = $this->number('from');
        $to = $this->number('to');

        if ($stream === null && ($from !== null || $to !== null)) {
            $this->warn($this->translated('unscoped_range'));

            return self::INVALID;
        }

        try {
            $report = $stream === null
                ? $verifier->verifyEverything()
                : new IntegrityReport([$verifier->verify($stream, $from, $to)]);

            $divergent = $this->option('projections') ? $this->reproject($projections, $report, $from, $to) : null;
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        $this->render($report);

        $break = $report->firstBreak() ?? $divergent;

        if ($break instanceof VerificationResult) {
            $this->error($break->message());

            return self::FAILURE;
        }

        $this->info($this->translated('intact', ['streams' => count($report->streams), 'entries' => $report->checked()]));

        return self::SUCCESS;
    }

    /**
     * The projection is checked after the chain and never instead of it. It reads a second table, so
     * it is asked for rather than assumed, and it is asked of the same streams the chain walk covered.
     */
    private function reproject(Projections $projections, IntegrityReport $report, ?int $from, ?int $to): ?VerificationResult
    {
        foreach ($report->streams as $verification) {
            $divergent = $projections->verify($verification->stream(), $from, $to);

            if ($divergent instanceof VerificationResult) {
                return $divergent;
            }
        }

        return null;
    }

    private function render(IntegrityReport $report): void
    {
        $this->table(
            [$this->translated('columns.stream'), $this->translated('columns.entries'), $this->translated('columns.chain'), $this->translated('columns.signatures')],
            array_map(fn (StreamVerification $verification): array => [
                $verification->stream(),
                $verification->chain->checked,
                $this->translated($verification->isIntact() ? 'sound' : 'broken'),
                $this->tally($verification->signatures),
            ], $report->streams),
        );
    }

    /**
     * @param  array<string, int>  $signatures
     */
    private function tally(array $signatures): string
    {
        $counted = [];

        foreach (SignatureState::cases() as $state) {
            if (($signatures[$state->value] ?? 0) > 0) {
                $counted[] = $signatures[$state->value].' '.$this->translated('states.'.$state->value);
            }
        }

        return $counted === [] ? '—' : implode(', ', $counted);
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function number(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function translated(string $key, array $replace = []): string
    {
        $line = trans('sentinel::sentinel.commands.verify.'.$key, $replace);

        return is_string($line) ? $line : '';
    }
}
