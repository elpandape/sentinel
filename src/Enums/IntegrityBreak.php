<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What was found wrong, and only what is wrong: every case here makes a verification fail. The
 * conditions a sound trail can be in — unsigned, signed with a key nobody holds — live in
 * SignatureState, because putting them here would invert isIntact() in silence.
 *
 * The sentence belongs to the reason and is built in one place. Two of them would agree until a
 * case needed a placeholder the other did not pass, and then one would quietly render its own key.
 */
enum IntegrityBreak: string
{
    case HashMismatch = 'hash_mismatch';
    case LinkMismatch = 'link_mismatch';
    case SequenceGap = 'sequence_gap';
    case SignatureMismatch = 'signature_mismatch';
    case ProjectionMismatch = 'projection_mismatch';
    case CheckpointMismatch = 'checkpoint_mismatch';

    public function message(string $stream, int $sequence, string $auditId): string
    {
        return (string) trans('sentinel::sentinel.integrity.'.$this->value, [
            'stream' => $stream,
            'sequence' => $sequence,
            'id' => $auditId,
        ]);
    }
}
