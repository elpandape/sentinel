<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * Somebody else's history, read in order and settled as this one.
 *
 * A batch at a time, each in a transaction of its own, because a source with ten million rows in it
 * is a source no single transaction survives and an import interrupted at hour six is one that must
 * not begin again from nothing. What makes beginning again cheap is not a bookmark but the identity
 * every imported entry derives from the row it came from: a second run offers the same identities,
 * the ledger says it has them, and they are dropped before a hash is computed for any of them.
 *
 * The read is a plain forward scan by key. An operator who knows where the last run stopped can say
 * so and skip ahead; one who does not can start from the beginning and pay a read per row, which is
 * the cheap half of the work.
 */
final readonly class Importer
{
    public function __construct(
        private DatabaseManager $databases,
        private Recorder $recorder,
        private Shape $shape,
        private Ledger $ledger,
        private Config $settings,
        private Repository $config,
    ) {}

    public function import(Origin $origin, string $table, ?string $connection, int $size, ?string $after, bool $rehearse): Report
    {
        $this->shape->verify($origin, $table, $connection);

        $mode = $this->config->get('sentinel.mode');
        $stages = $this->config->get('sentinel.pipeline');

        $this->config->set('sentinel.mode', Mode::Sync->value);
        $this->config->set('sentinel.pipeline', $this->carrying());

        try {
            return $this->walk($origin, $table, $connection, max(1, $size), $after, $rehearse);
        } finally {
            $this->config->set('sentinel.mode', $mode);
            $this->config->set('sentinel.pipeline', $stages);
        }
    }

    /**
     * Two settings, held for the length of the run and put back on the way out.
     *
     * It writes synchronously whatever the application is set to. Under the queued mode it would
     * push a job per batch and return having written nothing, so the report would be a lie and a run
     * resumed after it would find no work done; under the buffered one it would fill a store nobody
     * sized for a backfill. Neither is a mode somebody chose for this.
     *
     * And it resolves no context, because the context it needs is on the row. Every other capture
     * happens where the fact happened, so asking the runtime who the actor is answers correctly;
     * this one happens years later in somebody's terminal, and asking there would sign every
     * historical action with the name of whoever ran the migration. The stage that would do it steps
     * out for the duration. The rest run whole: labels keep what the row brought and gain what the
     * model and the configuration add, and the stages that discard go on discarding.
     *
     * @return list<class-string>
     */
    private function carrying(): array
    {
        return array_values(array_filter(
            $this->settings->pipelineStages(Pipeline::DEFAULT_STAGES),
            static fn (string $stage): bool => $stage !== ResolveContext::class,
        ));
    }

    private function walk(Origin $origin, string $table, ?string $connection, int $size, ?string $after, bool $rehearse): Report
    {
        $read = 0;
        $written = 0;
        $repeated = 0;
        $discarded = 0;
        $unmappable = [];
        $cursor = $after;

        while (($rows = $this->rows($table, $connection, $cursor, $size)) !== []) {
            $offered = [];

            foreach ($rows as $row) {
                $read++;
                $cursor = $row->text('id') ?? $cursor;

                $mapping = $origin->map($row);

                if ($mapping->data instanceof AuditData) {
                    $offered[] = $mapping->data;

                    continue;
                }

                $reason = $mapping->refused ?? '';
                $unmappable[$reason] = ($unmappable[$reason] ?? 0) + 1;
            }

            $already = $this->already($offered);
            $repeated += $already;

            $settled = $rehearse
                ? count($offered) - $already
                : $this->settle($offered);

            $written += $settled;
            $discarded += count($offered) - $already - $settled;
        }

        return new Report($read, $written, $repeated, $discarded, $unmappable, $cursor, $rehearse);
    }

    /**
     * The batch, handed over whole. No transaction is opened around it and that is deliberate
     * twice over: the ledger already wraps a batch write in one, so the all-or-nothing is there;
     * and opening another would defer the write to the commit of it, which is what a subject's own
     * transaction is supposed to do — leaving this holding an empty collection and reporting none
     * of what it had just written.
     *
     * @param  list<AuditData>  $offered
     */
    private function settle(array $offered): int
    {
        return $offered === [] ? 0 : $this->recorder->recordMany($offered)->count();
    }

    /**
     * How many of a batch this trail already holds. The ledger answers when it can, and the unique
     * index on the identity is what makes the write idempotent whether it answers or not — this is
     * only what keeps the report honest about the difference between a row nothing wanted and a row
     * that was already here.
     *
     * @param  list<AuditData>  $offered
     */
    private function already(array $offered): int
    {
        if (! $this->ledger instanceof Deduplicates) {
            return 0;
        }

        $identities = array_values(array_filter(array_map(
            static fn (AuditData $audit): ?string => $audit->capture_id,
            $offered,
        )));

        return $identities === [] ? 0 : count($this->ledger->settled($identities));
    }

    /**
     * @return list<Row>
     */
    private function rows(string $table, ?string $connection, ?string $after, int $size): array
    {
        $query = $this->databases->connection($connection)->table($table)->orderBy('id')->limit($size);

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return array_values(array_map(Row::of(...), $query->get()->all()));
    }
}
