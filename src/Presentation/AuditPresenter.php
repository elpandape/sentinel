<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Presentation;

use ElPandaPe\Sentinel\Capture\RelationCapture;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Transitions\Transition;
use ElPandaPe\Sentinel\Transitions\TransitionBuilder;
use Illuminate\Contracts\Translation\Translator;

/**
 * A trail, in a sentence. Every string it puts on screen comes from resources/lang, including the
 * event and severity names, because "changed" is a word shown to a person and not the value the
 * column holds.
 *
 * Impersonation is a separate line and not a clause bolted onto the plain one. The languages do
 * not agree on where "on behalf of" goes in a sentence, and a conditional concatenation would fix
 * English word order into every translation.
 */
final readonly class AuditPresenter
{
    public function __construct(private Translator $translator) {}

    public function entry(Audit $audit): string
    {
        $line = [
            'actor' => $this->party($audit->actor_type, $audit->actor_id, 'someone'),
            'event' => $this->event($audit->event),
            'subject' => $this->party($audit->subject_type, $audit->subject_id, 'something'),
        ];

        $sentence = $audit->impersonator_type === null
            ? $this->line('entry', $line)
            : $this->line('impersonated', [
                ...$line,
                'impersonator' => $this->party($audit->impersonator_type, $audit->impersonator_id, 'someone'),
            ]);

        return match ($audit->audit_type) {
            RelationCapture::AUDIT_TYPE => $this->relation($audit, $sentence),
            TransitionBuilder::AUDIT_TYPE => $this->transition($audit, $sentence),
            default => $sentence,
        };
    }

    /**
     * The version numbers a field's history shows are the subject's own and they skip: an
     * attribute that changed at v1, v4 and v7 belongs to an entry at each of those, and the real
     * number is what leads back to it. The ordinal counts the changes to the field itself.
     */
    public function fieldHistory(AuditCollection $entries, string $path): string
    {
        $pointer = Pointer::of($path);
        $ordinal = 0;

        return $entries
            ->map(function (Audit $audit) use ($pointer, &$ordinal): string {
                $ordinal++;

                return $this->line('field', [
                    'ordinal' => $ordinal,
                    'version' => $audit->version ?? 0,
                    'value' => $this->value($audit, $pointer),
                ]);
            })
            ->implode(PHP_EOL);
    }

    public function timeline(AuditCollection $entries): string
    {
        return $entries
            ->map(fn (Audit $audit): string => $this->line('timeline', [
                'time' => $audit->occurred_at->format('H:i'),
                'line' => $this->entry($audit),
            ]))
            ->implode(PHP_EOL);
    }

    /**
     * "Someone moved Invoice #1" leaves out the two states, which are the whole of what happened.
     * They go on the same line rather than underneath it: a transition is one step, unlike a sync
     * that touched several records at once.
     */
    private function transition(Audit $audit, string $sentence): string
    {
        $step = Transition::of($audit, null);

        return $this->line('transition', [
            'line' => $sentence,
            'from' => $this->scalar($step->from),
            'to' => $this->scalar($step->to),
        ]);
    }

    /**
     * A relation entry says nothing worth reading as one sentence: "Someone synced Team #1" leaves
     * out what was synced, which is the whole of what happened. The lines go underneath it, one per
     * record, with the sign saying what became of it.
     */
    private function relation(Audit $audit, string $sentence): string
    {
        /** @var array<array-key, mixed> $lines */
        $lines = $audit->getAttribute('changes') ?? [];

        $rendered = [];

        foreach ($lines as $line) {
            if (is_array($line)) {
                $rendered[] = $this->touched($line);
            }
        }

        if ($rendered === []) {
            return $sentence;
        }

        return implode(PHP_EOL, [
            $this->line('relation', ['line' => $sentence, 'relation' => $this->named($lines)]),
            ...$rendered,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function touched(array $line): string
    {
        $related = $this->party(
            $this->text($line, 'related_type'),
            $this->text($line, 'related_id'),
            'something',
        );

        return $this->line(match ($this->text($line, 'operation')) {
            RelationOperation::Attach->value => 'attached',
            RelationOperation::Detach->value => 'detached',
            default => 'repivoted',
        }, ['related' => $related]);
    }

    /**
     * @param  array<array-key, mixed>  $lines
     */
    private function named(array $lines): string
    {
        foreach ($lines as $line) {
            $relation = is_array($line) ? $this->text($line, 'relation') : null;

            if ($relation !== null) {
                return $relation;
            }
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function value(Audit $audit, string $pointer): string
    {
        $change = $audit->diffFor($pointer)->toArray()[0] ?? null;

        return $this->scalar($change['new'] ?? null);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => $this->line('nothing'),
            is_bool($value) => $this->line($value ? 'yes' : 'no'),
            is_scalar($value) => (string) $value,
            default => $this->line('structure'),
        };
    }

    private function party(?string $type, ?string $id, string $fallback): string
    {
        if ($type === null || $id === null) {
            return $this->line($fallback);
        }

        return $this->line('reference', ['type' => class_basename($type), 'id' => $id]);
    }

    private function event(string $event): string
    {
        return $this->translated("events.{$event}") ?? $event;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return $this->translated("presenter.{$key}", $replace) ?? $key;
    }

    /**
     * The one place that decides whether a line exists. Null covers both ways it can be missing:
     * the translator hands the key back when nothing answers it, and hands an array back when the
     * key names a group rather than a line.
     *
     * @param  array<string, int|string>  $replace
     */
    private function translated(string $key, array $replace = []): ?string
    {
        $namespaced = "sentinel::sentinel.{$key}";
        $line = $this->translator->get($namespaced, $replace);

        return is_string($line) && $line !== $namespaced ? $line : null;
    }
}
