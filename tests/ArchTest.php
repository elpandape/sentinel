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

arch('contracts are interfaces and live in their namespace')
    ->expect('ElPandaPe\Sentinel\Contracts')
    ->toBeInterfaces();

arch('classes are final')
    ->expect('ElPandaPe\Sentinel')
    ->classes()
    ->toBeFinal()
    ->ignoring(['ElPandaPe\Sentinel\Models', 'ElPandaPe\Sentinel\Testing']);

arch('the published contract suite stays open, because being extended is what it is for')
    ->expect('ElPandaPe\Sentinel\Testing')
    ->classes()
    ->toBeAbstract();

arch('models stay open so the configuration can replace them')
    ->expect('ElPandaPe\Sentinel\Models')
    ->classes()
    ->not->toBeFinal();

arch('the diff component depends on nothing in the package and nothing in the database layer')
    ->expect('ElPandaPe\Sentinel\Diff')
    ->not->toUse([
        'Illuminate\Database',
        'ElPandaPe\Sentinel\Capture',
        'ElPandaPe\Sentinel\Concerns',
        'ElPandaPe\Sentinel\Contracts',
        'ElPandaPe\Sentinel\Data',
        'ElPandaPe\Sentinel\Enums',
        'ElPandaPe\Sentinel\Events',
        'ElPandaPe\Sentinel\Exceptions',
        'ElPandaPe\Sentinel\Facades',
        'ElPandaPe\Sentinel\Integrity',
        'ElPandaPe\Sentinel\Ledger',
        'ElPandaPe\Sentinel\Models',
        'ElPandaPe\Sentinel\Query',
        'ElPandaPe\Sentinel\Snapshot',
        'ElPandaPe\Sentinel\Support',
    ]);

arch('the query surface states a query without knowing what answers it')
    ->expect('ElPandaPe\Sentinel\Query')
    ->not->toUse('Illuminate\Database');

arch('every resolver the package ships implements the contract and is final')
    ->expect('ElPandaPe\Sentinel\Context\Resolvers')
    ->toImplement(ElPandaPe\Sentinel\Contracts\Resolver::class)
    ->toBeFinal();

arch('the published contract suite depends on nothing in the test suite')
    ->expect('ElPandaPe\Sentinel\Testing')
    ->not->toUse('ElPandaPe\Sentinel\Tests');
