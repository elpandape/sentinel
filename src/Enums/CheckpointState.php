<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What the anchors said about a range, which is a question about a range and not about an entry.
 * That is why it is not a case of SignatureState: that enum answers what one signature says, and a
 * verifier asked for the signature of an entry could never return "no anchor covers this".
 *
 * None of these is a defect, which is why none is in IntegrityBreak. A range nobody anchored is
 * walked entry by entry and comes back with the same answer, only slower; a range whose entries
 * were retired on purpose is one the manifest accounts for and an anchor still answers for; an
 * anchor whose root no longer reproduces is a break, and it goes there.
 */
enum CheckpointState: string
{
    case Anchored = 'anchored';

    /**
     * The rows are gone and something explains where they went. It is never granted on the word of
     * the manifest alone: the manifest is unsigned and unhashed, so on its own it would turn
     * "delete the rows, insert one row" into a supported way of making evidence disappear.
     */
    case Archived = 'archived';

    case Absent = 'absent';
}
