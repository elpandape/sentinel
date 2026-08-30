<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use InvalidArgumentException;

final class QueryException extends InvalidArgumentException
{
    public static function unknownOperation(string $operation): self
    {
        return new self("[{$operation}] is not something that happens to a relation. Expected: attach, detach, update.");
    }

    public static function unsavedModel(string $model): self
    {
        return new self("Cannot query for a {$model} that has no key yet: no entry can reference it.");
    }

    public static function missingKey(string $type): self
    {
        return new self("Querying by the recorded type \"{$type}\" needs the key it was recorded with as the second argument.");
    }

    public static function backwardsPeriod(): self
    {
        return new self('A period that ends before it starts can only ever answer with nothing; pass the earlier moment first.');
    }

    public static function noLabels(): self
    {
        return new self('Narrowing by an empty list of labels asks nothing of an entry, so it would hand back the whole trail; name at least one label.');
    }

    public static function noField(): self
    {
        return new self('Narrowing by an empty field name asks nothing of an entry; name the attribute whose history you want.');
    }

    public static function noType(): self
    {
        return new self('Narrowing by an empty kind of entry asks nothing of an entry; name the kind you want, such as model, relation, custom, auth or transition.');
    }

    public static function unbounded(int $limit): self
    {
        return new self(
            "This filter matches at least {$limit} entries, and handing back the first {$limit} would look exactly like handing back all of them. "
            .'Narrow it, take() a prefix on purpose, or paginate() through the whole thing.',
        );
    }

    public static function unreachableLimit(int $limit): self
    {
        return new self("A read of {$limit} entries is not a read: ask for at least one.");
    }

    public static function unreachablePage(int $perPage, int $page): self
    {
        return new self("A page of {$perPage} entries numbered {$page} is not a page: both have to be at least one.");
    }

    public static function cannotEnumerateStreams(string $ledger): self
    {
        return new self(
            "[{$ledger}] cannot say which streams it holds, so it cannot be asked to verify all of them. "
            .'Name the stream to verify, or implement Contracts\\EnumeratesStreams on the driver.',
        );
    }

    public static function unreferenceable(string $type): self
    {
        return new self("Cannot query for {$type}: pass an Eloquent model, or the type and key the entry recorded.");
    }
}
