<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\VerifyCommand;
use ElPandaPe\Sentinel\Tests\Fixtures\Member;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use ElPandaPe\Sentinel\Tests\Fixtures\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\signingWith;

it('exits clean over an intact trail and says how much it walked', function (): void {
    seedTheReferenceChain();

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('Verified 10 entries across 2 streams')
        ->assertExitCode(Command::SUCCESS);
});

it('exits clean over a trail nobody has signed', function (): void {
    seedTheReferenceChain();

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('8 unsigned')
        ->assertExitCode(Command::SUCCESS);
});

it('exits broken and names the entry, when a row was changed', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('01JCHAIN0000000000000000S6 no longer reproduces its own hash at sequence 6')
        ->assertExitCode(Command::FAILURE);
});

it('exits broken over a signature its own key does not verify', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $audit = ledger()->write(auditData());

    DB::table(auditsTable())->where('id', $audit->id)->update(['signature' => str_repeat('0', 64)]);

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('does not verify')
        ->assertExitCode(Command::FAILURE);
});

it('walks only the stream it was given', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $this->artisan('sentinel:verify', ['--stream' => ReferenceChain::FORK])
        ->expectsOutputToContain('Verified 2 entries across 1 streams')
        ->assertExitCode(Command::SUCCESS);
});

it('walks only the range it was given', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $this->artisan('sentinel:verify', ['--stream' => ReferenceChain::STREAM, '--from' => '1', '--to' => '5'])
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a range that names no stream', function (): void {
    $this->artisan('sentinel:verify', ['--from' => '1'])
        ->expectsOutputToContain('a question about one stream')
        ->assertExitCode(Command::INVALID);
});

it('separates a chain it could not walk from a chain that is broken', function (): void {
    config()->set('sentinel.ledger.default', 'null');

    $this->artisan('sentinel:verify')
        ->expectsOutputToContain('cannot say which streams it holds')
        ->assertExitCode(Command::INVALID);
});

it('says nothing about the relation index unless it is asked to', function (): void {
    Team::query()->create(['name' => 'Ops'])->members()->attach(
        Member::query()->create(['name' => 'Ada'])->getKey(),
    );

    DB::table(auditRelationsTable())->delete();

    $this->artisan('sentinel:verify')->assertExitCode(Command::SUCCESS);
});

it('exits clean when the relation index still says what the entries say', function (): void {
    $team = Team::query()->create(['name' => 'Ops']);
    $team->members()->attach(Member::query()->create(['name' => 'Ada'])->getKey());

    $this->artisan('sentinel:verify', ['--projections' => true])
        ->expectsOutputToContain('The chain is intact.')
        ->assertExitCode(Command::SUCCESS);
});

it('reports the relation index as its own kind of defect when asked to', function (): void {
    $team = Team::query()->create(['name' => 'Ops']);
    $team->members()->attach(Member::query()->create(['name' => 'Ada'])->getKey());

    $audit = auditsOf($team)->last();

    DB::table(auditRelationsTable())->where('audit_id', $audit->id)->delete();

    $this->artisan('sentinel:verify', ['--projections' => true])
        ->expectsOutputToContain('The chain is intact')
        ->assertExitCode(Command::FAILURE);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(VerifyCommand::class)->getDescription())->toBe('Recorre la cadena y reporta lo que encuentra');
});

it('walks the anchors when asked, and says how much of it nobody read', function (): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);

    $this->artisan('sentinel:verify', ['--stream' => ReferenceChain::STREAM, '--depth' => 'anchors'])
        ->expectsOutputToContain('took 8 on the word of their anchors')
        ->assertExitCode(Command::SUCCESS);
});

it('folds every root again when asked, and finds the entry a rewritten hash broke', function (): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);

    DB::table(auditsTable())
        ->where('id', '01JCHAIN0000000000000000S2')
        ->update(['hash' => str_repeat('0', 64)]);

    $this->artisan('sentinel:verify', ['--stream' => ReferenceChain::STREAM, '--depth' => 'roots'])
        ->assertExitCode(Command::FAILURE);
});

it('reports what the anchors said in a column of their own', function (): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);

    $this->artisan('sentinel:verify', ['--depth' => 'anchors'])
        ->expectsOutputToContain('2 anchored')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a depth it does not have', function (): void {
    seedTheReferenceChain();

    $this->artisan('sentinel:verify', ['--depth' => 'nonesuch'])
        ->expectsOutputToContain('There is no nonesuch depth')
        ->assertExitCode(Command::INVALID);
});

it('refuses a range on a walk that does not take one', function (): void {
    seedTheReferenceChain();

    $this->artisan('sentinel:verify', ['--stream' => ReferenceChain::STREAM, '--from' => '2', '--depth' => 'anchors'])
        ->expectsOutputToContain('is what the entries depth answers')
        ->assertExitCode(Command::INVALID);
});
