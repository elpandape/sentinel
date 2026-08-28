<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A move the model says it does not make. It is raised before the entry reaches the pipeline, so
 * nothing is written and the chain spends no sequence number on a fact that was refused.
 *
 * User-facing, and therefore translated: this one surfaces where a person is doing something,
 * unlike the configuration errors, which surface where a developer is writing something.
 */
final class IllegalTransition extends RuntimeException
{
    public static function between(Model $subject, string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): self
    {
        $key = 'sentinel::sentinel.transitions.illegal';

        $line = trans($key, [
            'subject' => self::naming($subject),
            'attribute' => $attribute,
            'from' => self::named($from),
            'to' => self::named($to),
        ]);

        return new self(is_string($line) && $line !== $key ? $line : $key);
    }

    /**
     * The same shape the presenter puts a record in, from the same line of the language file:
     * a person reading the refusal and a person reading the trail should not have to learn two
     * ways of writing down which record is meant.
     */
    private static function naming(Model $subject): string
    {
        $reference = Reference::to($subject);

        $line = trans('sentinel::sentinel.presenter.reference', [
            'type' => class_basename($reference->type),
            'id' => $reference->id,
        ]);

        return is_string($line) ? $line : $reference->type;
    }

    /**
     * A column that held nothing is a state too — the one a record has before it has any — and
     * writing it as an empty string in the sentence would read as a state called "".
     */
    private static function named(bool|float|int|string|null $state): string
    {
        if ($state === null) {
            $line = trans('sentinel::sentinel.presenter.nothing');

            return is_string($line) ? $line : 'nothing';
        }

        return is_bool($state) ? var_export($state, true) : (string) $state;
    }
}
