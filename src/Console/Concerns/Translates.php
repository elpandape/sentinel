<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console\Concerns;

use Override;

/**
 * One way for a command to say something, and one thing that happens when it cannot.
 *
 * Every command carried its own copy of this and the copies had drifted into three behaviours: a
 * line with no translation behind it came out as the key in four of them, as nothing at all in
 * three, and the eighth read its translations inline and had no helper to drift. Nothing at all is
 * the worst of the three, because a command that prints an empty line reads as one that had
 * nothing to say. The key comes back instead: it is wrong in a way somebody can find.
 *
 * The prefix is the command's own name and not a constant written beside it. `sentinel:redact`
 * reads `commands.redact`, so renaming a command without renaming its keys stops the translation
 * rather than leaving it quietly answering to the old name.
 */
trait Translates
{
    #[Override]
    public function getDescription(): string
    {
        return $this->translated('description');
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    protected function translated(string $key, array $replace = []): string
    {
        $line = trans($this->key($key), $replace);

        return is_string($line) ? $line : $key;
    }

    private function key(string $key): string
    {
        return 'sentinel::sentinel.commands.'.str_replace('sentinel:', '', (string) $this->getName()).'.'.$key;
    }
}
