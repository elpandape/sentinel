<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What the anchors said about a range, which is a question about a range and not about an entry.
 * That is why it is not a case of SignatureState: that enum answers what one signature says, and a
 * verifier asked for the signature of an entry could never return "no anchor covers this".
 *
 * Neither case here is a defect, which is why neither is in IntegrityBreak. A range nobody anchored
 * is walked entry by entry and comes back with the same answer, only slower; an anchor whose root
 * no longer reproduces is a break, and it goes there.
 */
enum CheckpointState: string
{
    case Anchored = 'anchored';

    case Absent = 'absent';
}
