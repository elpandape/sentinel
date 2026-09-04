<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console;

use Composer\InstalledVersions;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Foundation\Console\AboutCommand;

/**
 * What `php artisan about` says about this package.
 *
 * Six answers, and they are the six a support conversation opens with: which version is installed,
 * what it does with a write, where it writes, what format the entries are in, and whether the two
 * things that change everything else — compliance mode and telemetry — are on.
 *
 * No key, no key identifier and no signer configuration. `about` is printed in terminals, pasted
 * into issues and captured by deploy logs, and the one thing that must never travel that way is
 * the material a signature is made with.
 *
 * The labels are in English and not in `resources/lang`, unlike everything the package's own
 * commands print. They sit inside a framework command whose every other row is untranslated, and a
 * section that alone changed language would read as a fault rather than as a courtesy.
 */
final readonly class About
{
    public const string SECTION = 'Sentinel';

    public const string PACKAGE = 'elpandape/sentinel';

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Config $config): array
    {
        return [
            'Version' => $this->version(),
            'Mode' => $config->mode()->value,
            'Ledger' => $config->ledger(),
            'Payload version' => (string) EntryBuilder::PAYLOAD_VERSION,
            'Compliance mode' => $this->flag($config->complianceEnabled()),
            'Telemetry' => $this->flag($config->telemetryEnabled()),
        ];
    }

    /**
     * Whatever Composer recorded at install time. An application running the package from a path
     * repository or a checkout has no version to report, and saying so is better than reporting a
     * number that came from somewhere else. Asked rather than caught: an installation Composer has
     * no record of is an ordinary situation, not an exceptional one.
     */
    private function version(): string
    {
        return InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev' : 'dev';
    }

    private function flag(bool $on): mixed
    {
        return AboutCommand::format($on, console: static fn (mixed $value): string => $value === true
            ? '<fg=yellow;options=bold>ENABLED</>'
            : 'OFF');
    }
}
