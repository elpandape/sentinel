<?php

declare(strict_types=1);

arch('source uses strict types')
    ->expect('ElPandaPe\Sentinel')
    ->toUseStrictTypes();

arch('no debugging functions left behind')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums live in the Enums namespace')
    ->expect('ElPandaPe\Sentinel\Enums')
    ->toBeEnums();

arch('exceptions live in the Exceptions namespace')
    ->expect('ElPandaPe\Sentinel\Exceptions')
    ->toImplement(Throwable::class);

arch('classes are final')
    ->expect('ElPandaPe\Sentinel')
    ->classes()
    ->toBeFinal()
    ->ignoring('ElPandaPe\Sentinel\Models');

arch('models stay open so the configuration can replace them')
    ->expect('ElPandaPe\Sentinel\Models')
    ->classes()
    ->not->toBeFinal();
