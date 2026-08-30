<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum IntegrityBreak: string
{
    case HashMismatch = 'hash_mismatch';
    case LinkMismatch = 'link_mismatch';
    case SequenceGap = 'sequence_gap';
    case SignatureMismatch = 'signature_mismatch';
}
