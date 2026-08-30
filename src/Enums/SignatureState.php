<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What the verification found out about one entry's signature, which is not the same question as
 * whether the chain holds. Three of these four live happily alongside an intact chain, and that is
 * why they are not cases of IntegrityBreak: an entry written before signing was switched on is not
 * a defect, and a key nobody can resolve is not a verdict.
 *
 * The distinction is the one RFC 4033 §5 draws for DNSSEC — secure, insecure, bogus, indeterminate —
 * and for the same reason: collapsing "not signed" into "signature failed" turns a report into noise.
 */
enum SignatureState: string
{
    case Signed = 'signed';

    case Unsigned = 'unsigned';

    case Invalid = 'invalid';

    case UnknownKey = 'unknown_key';
}
