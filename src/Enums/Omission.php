<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * Why something a restoration was asked for did not happen. The first five refuse the whole
 * restoration; the rest refuse one key and let the others through — a field dropped by a later
 * migration is an accident of the schema, while a broken entry is a question about trust.
 */
enum Omission: string
{
    case SubjectMissing = 'subject_missing';
    case EntryRedacted = 'entry_redacted';
    case EntryTampered = 'entry_tampered';
    case EntryStateless = 'entry_stateless';
    case Cancelled = 'cancelled';

    case UnknownField = 'unknown_field';
    case UnrecordedField = 'unrecorded_field';
    case IdentityField = 'identity_field';
    case RedactedField = 'redacted_field';
    case HashedField = 'hashed_field';
    case KeyUnavailable = 'key_unavailable';
    case RelatedMissing = 'related_missing';
    case Unchanged = 'unchanged';

    public function message(string $key = ''): string
    {
        return (string) trans('sentinel::sentinel.restore.'.$this->value, ['key' => $key]);
    }
}
