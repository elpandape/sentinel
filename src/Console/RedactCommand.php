<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Console\Command;
use Override;
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
 * not happen.
 */
final class RedactCommand extends Command
{
    /**
     * The option help stays in English, unlike everything the command prints: options are built in
     * the constructor, before the package has loaded its translations.
     */
    protected $signature = 'sentinel:redact
        {audit : The id of the entry whose contents are to be destroyed}
        {--reason= : Why it is being destroyed, kept on the entry and on the trail}
        {--actor= : Who ordered it, as type:id — required, because a console process resolves nobody}
        {--dry-run : Say what would be destroyed, and destroy nothing}';

    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

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
        } catch (Throwable $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info($this->translated('redacted', [
            'audit' => $tombstone->auditId,
            'stream' => $tombstone->stream,
            'sequence' => $tombstone->sequence,
        ]));

        return self::SUCCESS;
    }

    /**
     * The actor as `type:id`. The type is taken as written rather than resolved to a class: a morph
     * map is what decides what a type means here, and the command is not entitled to guess past it.
     */
    private function reference(string $actor): ?Reference
    {
        $split = strrpos($actor, ':');

        if (in_array($split, [false, 0, strlen($actor) - 1], true)) {
            return null;
        }

        return new Reference(substr($actor, 0, $split), substr($actor, $split + 1));
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function translated(string $key, array $replace = []): string
    {
        $line = __('sentinel::sentinel.commands.redact.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
