<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use Throwable;

/**
 * How long a policy keeps an entry, read once from the declared string.
 *
 * The grammar is checked before Carbon sees the value, because Carbon's own reader falls back to
 * relative-date parsing and would take 'tomorrow' as one day and 'next tuesday' as something that
 * depends on when it was asked. A retention period that means a different span on a Thursday is not
 * a retention period.
 *
 * Validity is decided by applying the interval rather than by counting its seconds: a month is not
 * a fixed number of them, and the only question worth asking is whether subtracting it moves back
 * in time at all.
 */
final readonly class Duration
{
    private const string NUMERIC = '/^(?:\h*\d+(?:\.\d+)?\h*[a-z]+\h*)+$/i';

    private const string ISO = '/^P(?=[\dT])/';

    private const string ANCHOR = '2000-01-01 00:00:00';

    private function __construct(
        public string $declared,
        private int $months,
        private CarbonInterval $remainder,
    ) {}

    public static function of(string $key, string $declared): self
    {
        if (preg_match(self::NUMERIC, $declared) !== 1 && preg_match(self::ISO, $declared) !== 1) {
            throw ConfigurationException::unreadableRetention($key, $declared);
        }

        try {
            $parsed = CarbonInterval::make($declared);
        } catch (Throwable) {
            $parsed = null;
        }

        $interval = $parsed ?? throw ConfigurationException::unreadableRetention($key, $declared);

        $period = new self(
            $declared,
            ($interval->y * 12) + $interval->m,
            new CarbonInterval(0, 0, 0, $interval->d, $interval->h, $interval->i, $interval->s, $interval->f),
        );

        $anchor = new CarbonImmutable(self::ANCHOR, 'UTC');

        return $period->cutoff($anchor) < $anchor
            ? $period
            : throw ConfigurationException::instantRetention($key, $declared);
    }

    /**
     * The months are taken first and without overflowing, then everything shorter than a month.
     * PHP reads the 31st of March minus one month as the 31st of February and lands on the 3rd of
     * March, so a period of '1 month' would release entries three days early on the three days of
     * the year the cron happens to run on a 29th, 30th or 31st — silently, and only sometimes.
     */
    public function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMonthsNoOverflow($this->months)->sub($this->remainder);
    }
}
