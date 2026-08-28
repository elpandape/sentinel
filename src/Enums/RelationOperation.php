<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What happened to one related record, not which API was called to make it happen. A sync() that
 * adds one and drops another writes one entry carrying an Attach line and a Detach line, so asking
 * for the times a relation gained something finds the ones sync() did — which is most of them.
 *
 * The API that produced the entry is not lost: it travels in the entry's metadata, which the chain
 * covers.
 */
enum RelationOperation: string
{
    case Attach = 'attach';
    case Detach = 'detach';
    case Update = 'update';
}
