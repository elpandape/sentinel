<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * One case per published filter. It is what a ledger names to declare which of them its
 * backend can translate, and what the refusal cites when one of them cannot.
 */
enum Filter: string
{
    case Subject = 'subject';
    case Actor = 'actor';
    case Event = 'event';
    case Severity = 'severity';
    case Source = 'source';
    case Tenant = 'tenant';
    case Transaction = 'transaction';
    case Trace = 'trace';
    case Period = 'period';

    public function method(): string
    {
        return match ($this) {
            self::Subject => 'for',
            self::Actor => 'by',
            self::Event => 'whereEvent',
            self::Severity => 'whereSeverity',
            self::Source => 'whereSource',
            self::Tenant => 'forTenant',
            self::Transaction => 'inTransaction',
            self::Trace => 'withTrace',
            self::Period => 'between',
        };
    }
}
