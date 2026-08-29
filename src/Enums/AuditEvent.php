<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case ForceDeleted = 'force_deleted';
    case Attached = 'attached';
    case Detached = 'detached';
    case Synced = 'synced';
    case Upserted = 'upserted';
    case Transition = 'transition';
    case Restore = 'restore';
    case Rekeyed = 'rekeyed';
    case Custom = 'custom';
}
