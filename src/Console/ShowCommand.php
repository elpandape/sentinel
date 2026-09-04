<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\ReadsOptions;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reads one entry, or a subject's life, out loud.
 *
 * It answers neither question itself. The narrowing is the Query API of v0.9.0 and the wording is
 * the presenter of v0.10.0, so what an operator reads in a terminal is the same sentence an
 * application renders in a page, in whichever language the application is set to.
 *
 * The two modes are asked for differently on purpose. An entry is named by its identifier, which
 * is the coordinate somebody copies out of a log or a report; a life is asked for by subject,
 * which is the coordinate somebody has when they do not have an entry yet. Asking for both at once
 * is a mistake rather than a preference, so it is refused instead of resolved.
 */
final class ShowCommand extends Command
{
    use ReadsOptions;
    use Translates;

    protected $signature = 'sentinel:show
        {audit? : The id of the entry to read out}
        {--subject= : Read out this subject\'s life instead, as type:id}
        {--limit=50 : How many entries of a life at most, newest last}';

    public function handle(Audit $audits, AuditQuery $query, AuditPresenter $presenter): int
    {
        $id = $this->argument('audit');
        $subject = $this->text('subject');

        if (is_string($id) && $id !== '' && $subject === null) {
            return $this->entry($audits, $presenter, $id);
        }

        if ($subject !== null && ! is_string($id)) {
            return $this->life($query, $presenter, $subject);
        }

        $this->warn($this->translated('ambiguous'));

        return self::INVALID;
    }

    /**
     * Found by primary key rather than through the Query API, which has no filter on an entry's
     * own identifier — the same route sentinel:redact takes for the same reason. Under compliance
     * mode that route leaves no access record, which is a hole in the query surface and not one
     * this command opens: it is on the list the release candidate sweeps.
     */
    private function entry(Audit $audits, AuditPresenter $presenter, string $id): int
    {
        $audit = $audits->newQuery()->find($id);

        if (! $audit instanceof Audit) {
            $this->warn($this->translated('unknown_entry', ['audit' => $id]));

            return self::INVALID;
        }

        $this->line($presenter->entry($audit));

        return self::SUCCESS;
    }

    private function life(AuditQuery $query, AuditPresenter $presenter, string $subject): int
    {
        $reference = $this->reference($subject);

        if (! $reference instanceof Reference) {
            $this->warn($this->translated('unreadable_subject', ['subject' => $subject]));

            return self::INVALID;
        }

        try {
            $entries = $query->byOccurrence()
                ->for($reference->type, $reference->id)
                ->take($this->number('limit') ?? 50)
                ->get();
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        if ($entries->isEmpty()) {
            $this->info($this->translated('nothing', ['subject' => $subject]));

            return self::SUCCESS;
        }

        $this->line($presenter->timeline($entries));

        return self::SUCCESS;
    }
}
