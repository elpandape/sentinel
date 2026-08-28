<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\changing;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\insertAudit;

/**
 * SQLite stores this column as bare text with no check, so a row the package did not write can
 * hold something json_each cannot walk. Unguarded, that does not fail the query — PDO::fetchAll,
 * which is what Laravel reads with, hands back a partial result and no exception, and an audit
 * that answers with fewer entries than it should while looking complete is the one failure this
 * package cannot have.
 */
it('keeps answering with a row whose changes are not an array of objects', function (string $poison): void {
    app(DatabaseLedger::class)->write(auditData(changing('mailed', '/email')));

    insertAudit(['id' => frozenUlid('POISON'), 'sequence' => 99, 'event' => 'poisoned']);
    DB::table(auditsTable())->where('id', frozenUlid('POISON'))->update(['changes' => $poison]);

    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
})->with([
    'a scalar element' => '["/email",{"path":"/x"}]',
    'an object instead of an array' => '{"path":"/email"}',
    'a bare scalar' => '"/email"',
    'a number' => '123',
    'an element with no path' => '[{"op":"replace"}]',
    'a path that is not a string' => '[{"path":{"x":"/email"}}]',
]);

it('keeps answering with a row whose changes are not json at all', function (string $poison): void {
    app(DatabaseLedger::class)->write(auditData(changing('mailed', '/email')));

    insertAudit(['id' => frozenUlid('GARBAGE'), 'sequence' => 98, 'event' => 'garbled']);
    DB::table(auditsTable())->where('id', frozenUlid('GARBAGE'))->update(['changes' => $poison]);

    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
})->with([
    'unparseable text' => 'not json at all',
    'the empty string' => '',
])->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'only SQLite stores this column as text, so only there can it hold something that is not JSON',
);
