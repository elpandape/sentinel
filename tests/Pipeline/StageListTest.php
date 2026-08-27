<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\PassThroughStage;
use ElPandaPe\Sentinel\Tests\Fixtures\SecondStampingStage;
use ElPandaPe\Sentinel\Tests\Fixtures\StampingStage;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('reads the list in the order it is declared', function (): void {
    expect(sentinelConfig(['pipeline' => [SecondStampingStage::class, StampingStage::class]])->pipelineStages([]))
        ->toBe([SecondStampingStage::class, StampingStage::class]);
});

it('falls back to the package order when the published config left the list empty', function (): void {
    expect(sentinelConfig(['pipeline' => []])->pipelineStages([PassThroughStage::class]))
        ->toBe([PassThroughStage::class]);
});

it('falls back to the package order when the key is missing altogether', function (): void {
    expect(sentinelConfig(['pipeline' => null])->pipelineStages([PassThroughStage::class]))
        ->toBe([PassThroughStage::class]);
});

it('refuses a list that is not a list', function (): void {
    sentinelConfig(['pipeline' => 'FilterUnchanged'])->pipelineStages([]);
})->throws(ConfigurationException::class, 'sentinel.pipeline');

it('refuses an entry that is not a class-string', function (): void {
    sentinelConfig(['pipeline' => [42]])->pipelineStages([]);
})->throws(ConfigurationException::class, 'a list of stage class-strings');

it('refuses a class that does not exist', function (): void {
    sentinelConfig(['pipeline' => ['App\Sentinel\Nowhere']])->pipelineStages([]);
})->throws(ConfigurationException::class, 'App\Sentinel\Nowhere');

it('refuses a class that is not a stage', function (): void {
    sentinelConfig(['pipeline' => [stdClass::class]])->pipelineStages([]);
})->throws(ConfigurationException::class, 'Transformer');
