<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

/**
 * One package this trail can be brought in from.
 *
 * What differs between two of them is small and it is all here: where their history lives, which
 * columns have to be there for the table to be theirs, and what one of their rows means. Reading in
 * order, batching, resuming, counting and settling are the same work whichever package wrote the
 * rows, so none of it is behind this.
 *
 * @internal Two implementations are not evidence enough to freeze an abstraction. Until a third
 * origin exists this stays inside the package: a contract published early is a contract that gets
 * published wrong, and §11.3 already shows what publishing one looks like when there is a reason.
 */
interface Origin
{
    /**
     * What the caller names on the command line, and what goes into a row's derived identity so
     * two packages numbering their rows from one cannot collide.
     */
    public function name(): string;

    /**
     * Where the history lives when nobody says otherwise. Both packages let an application move it,
     * so this is a default and never an assumption.
     */
    public function table(): string;

    /**
     * Every column that has to be present for the table to be one of these. Extra columns are an
     * application's business; a missing one means this is not the table it was pointed at.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * What one row means, as an entry this package could have written itself. Everything it cannot
     * know is left out rather than filled in, and a row it cannot read at all comes back as a
     * refusal with its reason rather than as a guess.
     */
    public function map(Row $row): Mapping;
}
