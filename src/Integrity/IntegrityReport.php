<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

/**
 * Every stream that was walked, in the order the ledger named them. It is what `sentinel:verify`
 * prints and what an application reads when it wants the whole trail rather than one chain: a
 * verification of a single stream is a report with one entry in it, so there is one shape to read
 * and not two.
 */
final readonly class IntegrityReport
{
    /**
     * @param  list<StreamVerification>  $streams
     */
    public function __construct(public array $streams) {}

    public function isIntact(): bool
    {
        return array_all($this->streams, static fn (StreamVerification $verification): bool => $verification->isIntact());
    }

    public function checked(): int
    {
        return array_sum(array_map(
            static fn (StreamVerification $verification): int => $verification->chain->checked,
            $this->streams,
        ));
    }

    /**
     * The first thing that was wrong, so a reader who wants one line has one to take. It carries the
     * stream it belongs to, which is why the stream itself is not handed back around it.
     */
    public function firstBreak(): ?VerificationResult
    {
        foreach ($this->streams as $verification) {
            if (! $verification->isIntact()) {
                return $verification->break();
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function signatures(): array
    {
        $tally = [];

        foreach ($this->streams as $verification) {
            foreach ($verification->signatures as $state => $count) {
                $tally[$state] = ($tally[$state] ?? 0) + $count;
            }
        }

        return $tally;
    }
}
