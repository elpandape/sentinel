<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Console\Concerns\ReadsOptions;
use ElPandaPe\Sentinel\Console\Concerns\Translates;
use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Exceptions\RedactionException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Console\Command;
use Throwable;

/**
 * Destroys the contents of one entry and leaves everything else standing.
 *
 * It drives the service rather than being the operation, because an erasure request arrives through
 * application code as often as through a terminal, and a command cannot hand back the tombstone, take
 * an Eloquent actor or join the caller's transaction.
 *
 * --actor is required and it is not ceremony. Nothing resolves an actor in a console process, so a
 * command that let it default would write the one entry in the whole package whose entire purpose is
 * to say who did this, with nobody's name on it.
 *
 * Three exit codes, the same three the other commands use: zero for a redaction and for an entry that
 * was already redacted, failure for an entry this refuses to touch, and invalid for a run that could
 * not happen. The refusals are the two exceptions the service raises deliberately; anything else it
 * throws is the second thing, and reporting a dead connection as a refusal would tell an operator
 * their entry is archived when nothing of the sort is true.
 */
final class RedactCommand extends Command
{
    use ReadsOptions;
    use Translates;

    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:redact
        {audit : The id of the entry whose contents are to be destroyed}
        {--reason= : Why it is being destroyed, kept on the entry and on the trail}
        {--actor= : Who ordered it, as type:id — required, because a console process resolves nobody}
        {--dry-run : Say what would be destroyed, and destroy nothing}';

    public function handle(Redactor $redactor, Audit $audits): int
    {
        $reason = $this->text('reason');
        $actor = $this->text('actor');

        if ($reason === null || $actor === null) {
            $this->warn($this->translated('incomplete'));

            return self::INVALID;
        }

        $reference = $this->reference($actor);

        if (! $reference instanceof Reference) {
            $this->warn($this->translated('unreadable_actor', ['actor' => $actor]));

            return self::INVALID;
        }

        $id = (string) $this->argument('audit');
        $entry = $audits->newQuery()->find($id);

        if (! $entry instanceof Audit) {
            $this->warn($this->translated('unknown_entry', ['audit' => $id]));

            return self::INVALID;
        }

        return $this->apply($redactor, $entry, $reason, $reference);
    }

    private function apply(Redactor $redactor, Audit $entry, string $reason, Reference $actor): int
    {
        if ($this->option('dry-run')) {
            $this->info($this->translated('would', [
                'audit' => $entry->id,
                'stream' => $entry->stream,
                'sequence' => $entry->sequence,
            ]));

            return self::SUCCESS;
        }

        try {
            $tombstone = $redactor->redact($entry, $reason, $actor);
        } catch (ComplianceException|RedactionException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        } catch (Throwable $failure) {
            $this->error($this->translated('failed', ['reason' => $failure->getMessage()]));

            return self::INVALID;
        }

        $this->info($this->translated('redacted', [
            'audit' => $tombstone->auditId,
            'stream' => $tombstone->stream,
            'sequence' => $tombstone->sequence,
        ]));

        return self::SUCCESS;
    }
}
