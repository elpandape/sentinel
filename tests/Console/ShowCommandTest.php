<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\ShowCommand;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('reads out the entry it was named', function (): void {
    $written = ledger()->write(auditData([
        'event' => 'updated',
        'subject_type' => 'invoice',
        'subject_id' => '77',
    ]));

    $this->artisan('sentinel:show', ['audit' => $written->id])
        ->expectsOutputToContain('invoice')
        ->assertSuccessful();
});

it('reads out a subject life in the order the things happened', function (): void {
    foreach (['created', 'updated'] as $event) {
        ledger()->write(auditData(['event' => $event, 'subject_type' => 'invoice', 'subject_id' => '77']));
    }

    $this->artisan('sentinel:show', ['--subject' => 'invoice:77'])
        ->expectsOutputToContain('invoice')
        ->assertSuccessful();
});

it('says nothing has been recorded rather than printing an empty life', function (): void {
    $this->artisan('sentinel:show', ['--subject' => 'invoice:404'])
        ->expectsOutputToContain('Nothing has been recorded')
        ->assertSuccessful();
});

it('refuses an id no entry answers to', function (): void {
    $this->artisan('sentinel:show', ['audit' => '01JTHISISNOTANENTRYATALL00'])
        ->expectsOutputToContain('No entry with id')
        ->assertExitCode(2);
});

it('refuses a subject that does not read as type and id', function (): void {
    $this->artisan('sentinel:show', ['--subject' => 'invoice'])
        ->expectsOutputToContain('is not readable as type:id')
        ->assertExitCode(2);
});

it('refuses to guess when it is given both an entry and a subject', function (): void {
    $written = ledger()->write(auditData());

    $this->artisan('sentinel:show', ['audit' => $written->id, '--subject' => 'invoice:77'])
        ->expectsOutputToContain('two questions')
        ->assertExitCode(2);
});

it('refuses to guess when it is given neither', function (): void {
    $this->artisan('sentinel:show')
        ->expectsOutputToContain('two questions')
        ->assertExitCode(2);
});

it('reports a trail it could not read instead of exiting sound', function (): void {
    Schema::drop(sentinelConfig()->table('audits'));

    $this->artisan('sentinel:show', ['--subject' => 'invoice:77'])
        ->expectsOutputToContain('Nothing was read')
        ->assertExitCode(2);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(ShowCommand::class)->getDescription())
        ->toBe('Lee en voz alta un asiento, o la vida de un sujeto');
});
