<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use LogicException;

final class DispatchException extends LogicException
{
    /**
     * An entry arriving from another process with a place in the chain already on it. The sequence,
     * the hash and the link are assigned in the ledger, inside the same operation as the write, in
     * every mode — it is the only reason a tamper-evident chain and an asynchronous write are
     * compatible at all. Taking the proposal would mean writing an entry into a position nobody
     * read the chain to find.
     */
    public static function proposedItsOwnPlaceInTheChain(string $column): self
    {
        return new self(
            "Sentinel was handed an entry that already names its [{$column}]. A capture never proposes "
            .'where in the chain a fact belongs: the ledger reads the chain and assigns it, in the same '
            .'operation as the write, in every mode.',
        );
    }

    /**
     * A payload with nothing to say. Unknown keys are dropped and missing optional ones take their
     * default, so what is left is the handful an entry cannot be read without — what kind of fact
     * it is, what happened, and when it happened.
     */
    public static function incompletePayload(string $key): self
    {
        return new self(
            "Sentinel was handed an entry with no [{$key}]. An entry that cannot say what happened, "
            .'or when, is not one the ledger can settle.',
        );
    }
}
