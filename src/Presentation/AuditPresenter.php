<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Presentation;

use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
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

        return $audit->impersonator_type === null
            ? $this->line('entry', $line)
            : $this->line('impersonated', [
                ...$line,
                'impersonator' => $this->party($audit->impersonator_type, $audit->impersonator_id, 'someone'),
            ]);
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
