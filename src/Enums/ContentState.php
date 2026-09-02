<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What an entry's content says about itself, which is a different question from whether anything is
 * wrong. It is not a case of IntegrityBreak for the reason v0.18.0 gave for signatures and v0.18.1
 * for checkpoints: a state that coexists with a healthy chain would invert isIntact() in silence.
 * It is not a case of CheckpointState either, which answers for a range and would make the anchors
 * read a third column per row — the cost model they exist to avoid.
 *
 * The discriminant is redacted_at and the hash only corroborates. An entry whose content columns
 * were already empty redacts to the very bytes it already had, so its second hash equals its first
 * and both "rehashes to the original" and "rehashes to the redacted state" are true at once. Asked
 * in that order, a tombstone over an already-empty entry is Redacted; asked the other way it is
 * silently Sealed.
 */
enum ContentState: string
{
    case Sealed = 'sealed';

    case Redacted = 'redacted';

    case Altered = 'altered';
}
