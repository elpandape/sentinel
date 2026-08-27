<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Foundation\Application;

/**
 * The one process the suite cannot be by itself: a console that is not a test run.
 */
final class ConsoleApplication extends Application
{
    public function runningInConsole(): bool
    {
        return true;
    }

    public function runningUnitTests(): bool
    {
        return false;
    }
}
