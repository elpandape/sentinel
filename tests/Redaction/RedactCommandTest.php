<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\RedactCommand;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;

it('destroys the contents of the entry it was given and says where it was', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request 4711',
        '--actor' => 'member:77',
    ])->expectsOutputToContain('Destroyed the contents')->assertSuccessful();

    $reloaded = Audit::query()->findOrFail($written->id);

    expect($reloaded->before)->toBeNull()
        ->and($reloaded->verifyContent())->toBe(ContentState::Redacted);
});

it('takes the actor it was named and puts it on the trail', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request',
        '--actor' => 'member:77',
    ])->assertSuccessful();

    $trail = Audit::query()->where('source_audit_id', $written->id)->firstOrFail();

    expect($trail->actor_type)->toBe('member')
        ->and($trail->actor_id)->toBe('77');
});

it('refuses to run without an actor, because a console process resolves nobody', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', ['audit' => $written->id, '--reason' => 'erasure request'])
        ->expectsOutputToContain('needs both --reason and --actor')
        ->assertExitCode(2);

    expect(Audit::query()->findOrFail($written->id)->before)->toBe(['a' => 1]);
});

it('refuses to run without a reason', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', ['audit' => $written->id, '--actor' => 'member:77'])
        ->assertExitCode(2);
});

it('refuses an actor that does not read as type and id', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request',
        '--actor' => 'member',
    ])->expectsOutputToContain('is not readable as type:id')->assertExitCode(2);
});

it('refuses an id no entry answers to', function (): void {
    $this->artisan('sentinel:redact', [
        'audit' => '01JNOSUCHENTRY000000000000',
        '--reason' => 'erasure request',
        '--actor' => 'member:77',
    ])->expectsOutputToContain('No entry with id')->assertExitCode(2);
});

it('says what it would destroy and destroys nothing', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request',
        '--actor' => 'member:77',
        '--dry-run' => true,
    ])->expectsOutputToContain('Would destroy the contents')->assertSuccessful();

    expect(Audit::query()->findOrFail($written->id)->before)->toBe(['a' => 1])
        ->and(Audit::query()->where('source_audit_id', $written->id)->count())->toBe(0);
});

it('reports the refusal of an entry that no longer reproduces its hash', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    DB::table(auditsTable())->where('id', $written->id)->update(['before' => json_encode(['a' => 2])]);

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request',
        '--actor' => 'member:77',
    ])->expectsOutputToContain('does not reproduce its own hash')->assertExitCode(1);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(RedactCommand::class)->getDescription())
        ->toBe('Destruye el contenido de un asiento y deja en pie todo lo demás');
});

it('tells a run that could not happen apart from an entry it refuses to touch', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    app()->instance(Ledger::class, new FailingLedger);

    $this->artisan('sentinel:redact', [
        'audit' => $written->id,
        '--reason' => 'erasure request',
        '--actor' => 'member:77',
    ])->expectsOutputToContain('Nothing was redacted')->assertExitCode(2);
});
