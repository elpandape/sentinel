<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\CheckpointState;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Exceptions\QueryException;
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
    private const string ENTRIES = 'entries';

    private const string ANCHORS = 'anchors';

    private const array DEPTHS = [self::ENTRIES, self::ANCHORS, 'roots'];

    /**
     * The option help stays in English, unlike everything the command prints. Options are built in
     * the constructor, before the package has loaded its translations, so a translated one would
     * resolve to its own key — and Laravel's own commands do not translate theirs either.
     */
    protected $signature = 'sentinel:verify
        {--stream= : Verify this stream instead of every one}
        {--from= : First sequence to verify; needs a stream and the entries depth}
        {--to= : Last sequence to verify; needs a stream and the entries depth}
        {--depth=entries : entries reads and rehashes every one; roots folds each range again; anchors reads only the anchors}
        {--projections : Also check that the relation index still matches the entries}';

    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

    public function handle(Verifier $verifier, Projections $projections, Ledger $ledger): int
    {
        $stream = $this->text('stream');
        $from = $this->number('from');
        $to = $this->number('to');
        $depth = $this->text('depth') ?? self::ENTRIES;

        if ($stream === null && ($from !== null || $to !== null)) {
            $this->warn($this->translated('unscoped_range'));

            return self::INVALID;
        }

        if (! in_array($depth, self::DEPTHS, true)) {
            $this->warn($this->translated('unknown_depth', ['depth' => $depth, 'accepted' => implode(', ', self::DEPTHS)]));

            return self::INVALID;
        }

        if ($depth !== self::ENTRIES && ($from !== null || $to !== null)) {
            $this->warn($this->translated('unscoped_depth'));

            return self::INVALID;
        }

        try {
            $report = $this->walk($verifier, $ledger, $stream, $from, $to, $depth);

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

        $this->info($report->covered() === 0
            ? $this->translated('intact', ['streams' => count($report->streams), 'entries' => $report->checked()])
            : $this->translated('anchored', [
                'streams' => count($report->streams),
                'entries' => $report->checked(),
                'covered' => $report->covered(),
            ]));

        return self::SUCCESS;
    }

    /**
     * Which walk the operator asked for. The shallow two are asked of every stream the ledger can
     * name, the same way the deep one already is, because a range is a question the entry walk
     * answers and neither of them takes one.
     */
    private function walk(Verifier $verifier, Ledger $ledger, ?string $stream, ?int $from, ?int $to, string $depth): IntegrityReport
    {
        if ($depth === self::ENTRIES) {
            return $stream === null
                ? $verifier->verifyEverything()
                : new IntegrityReport([$verifier->verify($stream, $from, $to)]);
        }

        $streams = $stream !== null ? [$stream] : $this->named($ledger);

        return new IntegrityReport(array_map(
            fn (string $name): StreamVerification => $depth === self::ANCHORS
                ? $verifier->verifyAnchors($name)
                : $verifier->verifyRoots($name),
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
            [
                $this->translated('columns.stream'),
                $this->translated('columns.entries'),
                $this->translated('columns.chain'),
                $this->translated('columns.anchors'),
                $this->translated('columns.signatures'),
            ],
            array_map(fn (StreamVerification $verification): array => [
                $verification->stream(),
                $verification->chain->checked,
                $this->translated($verification->isIntact() ? 'sound' : 'broken'),
                $this->anchors($verification),
                $this->tally($verification->signatures),
            ], $report->streams),
        );
    }

    /**
     * What the anchors said about this stream, and how much of it they answered for. A walk that
     * read every entry has nothing to say here, and says nothing rather than a zero that would read
     * as an absence of anchors.
     */
    private function anchors(StreamVerification $verification): string
    {
        $counted = [];

        foreach (CheckpointState::cases() as $state) {
            if (($verification->anchors[$state->value] ?? 0) > 0) {
                $counted[] = $verification->anchors[$state->value].' '.$this->translated('anchor_states.'.$state->value);
            }
        }

        return $counted === []
            ? '—'
            : implode(', ', $counted).' ('.$this->translated('covering', ['covered' => $verification->covered]).')';
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
