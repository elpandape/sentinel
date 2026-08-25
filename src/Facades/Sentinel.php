<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ElPandaPe\Sentinel\Support\Config config()
 * @method static \ElPandaPe\Sentinel\Context\ExecutionContext context()
 * @method static bool isRecording()
 * @method static void pause()
 * @method static void resume()
 * @method static mixed withoutAuditing(\Closure $callback)
 * @method static mixed withContext(array<string, mixed> $context, \Closure $callback)
 *
 * @see \ElPandaPe\Sentinel\Sentinel
 */
final class Sentinel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ElPandaPe\Sentinel\Sentinel::class;
    }
}
