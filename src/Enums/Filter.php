<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;

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

    case Tag = 'tag';

    case FieldChanged = 'field';

    case Version = 'version';

    case Relation = 'relation';

    case Related = 'related';

    case Operation = 'operation';

    case Type = 'type';

    /**
     * @return list<self>
     */
    public static function answeredBy(Ledger $ledger): array
    {
        return $ledger instanceof DeclaresFilters ? $ledger->supportedFilters() : self::assumed();
    }

    /**
     * What a driver is taken to answer when it does not declare anything. It is the set as it
     * stood in v0.9.0, and it does not grow: a driver written against that surface never named
     * the filters published after it, and assuming it can translate them would have it quietly
     * dropping a criterion instead of refusing it — the one failure DeclaresFilters exists to
     * prevent. A filter published from v0.10.0 on is answered only by a driver that names it.
     *
     * @return list<self>
     */
    public static function assumed(): array
    {
        return [
            self::Subject,
            self::Actor,
            self::Event,
            self::Severity,
            self::Source,
            self::Tenant,
            self::Transaction,
            self::Trace,
            self::Period,
        ];
    }

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
            self::Tag => 'whereTag',
            self::FieldChanged => 'whereFieldChanged',
            self::Version => 'whereVersion',
            self::Relation => 'whereRelation',
            self::Related => 'whereRelated',
            self::Operation => 'whereOperation',
            self::Type => 'whereType',
        };
    }
}
