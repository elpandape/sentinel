<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\Article;
use ElPandaPe\Sentinel\Tests\Fixtures\Author;
use ElPandaPe\Sentinel\Tests\Fixtures\Bulletin;
use ElPandaPe\Sentinel\Tests\Fixtures\Clipping;
use ElPandaPe\Sentinel\Tests\Fixtures\Draft;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\lineOf;
use function ElPandaPe\Sentinel\Tests\linesOf;
use function ElPandaPe\Sentinel\Tests\presenter;

beforeEach(function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->ada = Author::query()->create(['name' => 'Ada', 'code' => 'ada']);
        $this->grace = Author::query()->create(['name' => 'Grace', 'code' => 'grace']);
        $this->article = Article::query()->create(['title' => 'Notes', 'author_id' => $this->ada->getKey()]);
    });
});

it('writes one entry on the parent it left and one on the parent it joined', function (): void {
    $this->article->update(['author_id' => $this->grace->getKey()]);

    $left = auditsOf($this->ada)->sole();
    $joined = auditsOf($this->grace)->sole();

    expect($left->audit_type)->toBe('relation')
        ->and($left->event)->toBe(AuditEvent::Detached->value)
        ->and($left->metadata)->toBe(['api' => 'foreign_key'])
        ->and(lineOf($left)['operation'])->toBe('detach')
        ->and(lineOf($left)['relation'])->toBe('articles')
        ->and(lineOf($left)['related_type'])->toBe($this->article->getMorphClass())
        ->and(lineOf($left)['related_id'])->toBe((string) $this->article->getKey())
        ->and(lineOf($left)['pivot_before'])->toBeNull()
        ->and(lineOf($left)['pivot_after'])->toBeNull()
        ->and($joined->event)->toBe(AuditEvent::Attached->value)
        ->and(lineOf($joined)['operation'])->toBe('attach');
});

it('writes a single entry when one of the two ends is nobody', function (): void {
    $this->article->update(['author_id' => null]);

    expect(auditsOf($this->ada)->sole()->event)->toBe(AuditEvent::Detached->value);

    $this->article->update(['author_id' => $this->grace->getKey()]);

    expect(auditsOf($this->grace)->sole()->event)->toBe(AuditEvent::Attached->value)
        ->and(auditsOf($this->ada))->toHaveCount(1);
});

it('writes nothing when the foreign key did not move, however much else did', function (): void {
    $this->article->update(['title' => 'Notes on the Analytical Engine']);

    expect(auditsOf($this->ada))->toBeEmpty()
        ->and(auditsOf($this->article))->toHaveCount(1);
});

it('writes nothing for a child that declares no parents', function (): void {
    $draft = Sentinel::withoutAuditing(fn (): Draft => Draft::query()->create(['author_id' => $this->ada->getKey()]));

    $draft->update(['author_id' => $this->grace->getKey()]);

    expect(auditsOf($this->ada))->toBeEmpty()
        ->and(auditsOf($this->grace))->toBeEmpty()
        ->and(auditsOf($draft))->toHaveCount(1);
});

it('writes nothing at all while auditing is paused', function (): void {
    Sentinel::withoutAuditing(function (): void {
        $this->article->update(['author_id' => $this->grace->getKey()]);
    });

    expect(Audit::query()->count())->toBe(0);
});

it('correlates the three entries of one hand-over under one request', function (): void {
    httpRequest('/articles');

    $this->article->update(['author_id' => $this->grace->getKey()]);

    $identifiers = Audit::query()->pluck('request_id')->unique();

    expect(Audit::query()->count())->toBe(3)
        ->and($identifiers)->toHaveCount(1)
        ->and($identifiers->first())->toBeString();
});

it('names a parent that is gone, because the key is the name', function (): void {
    Sentinel::withoutAuditing(fn (): mixed => $this->ada->delete());

    $this->article->update(['author_id' => $this->grace->getKey()]);

    expect(auditsOf($this->ada)->sole()->subject_id)->toBe((string) $this->ada->getKey());
});

it('names a parent by its primary key when the foreign key points at another column', function (): void {
    $bulletin = Sentinel::withoutAuditing(fn (): Bulletin => Bulletin::query()->create(['editor_code' => 'ada']));

    $bulletin->update(['editor_code' => 'grace']);

    expect(auditsOf($this->ada)->sole()->subject_id)->toBe((string) $this->ada->getKey())
        ->and(auditsOf($this->grace)->sole()->subject_id)->toBe((string) $this->grace->getKey())
        ->and(lineOf(auditsOf($this->grace)->sole())['relation'])->toBe('edited');
});

it('writes nothing for an end that names nobody it can find', function (): void {
    $bulletin = Sentinel::withoutAuditing(fn (): Bulletin => Bulletin::query()->create(['editor_code' => 'ada']));

    $bulletin->update(['editor_code' => 'nobody']);

    expect(auditsOf($this->ada)->sole()->event)->toBe(AuditEvent::Detached->value)
        ->and(Audit::query()->where('audit_type', 'relation')->count())->toBe(1);
});

it('refuses a declaration that does not name a plain belongsTo', function (string $relation): void {
    $clipping = Sentinel::withoutAuditing(fn (): Clipping => Clipping::query()->create(['author_id' => $this->ada->getKey()]));
    $clipping->auditParents = [$relation => 'clippings'];

    $clipping->update(['author_id' => $this->grace->getKey()]);
})->with([
    'a relation that does not exist' => ['editor'],
    'a hasMany' => ['articles'],
    'a morphTo' => ['subject'],
])->throws(ConfigurationException::class, 'auditParents');

it('answers relationHistory and the presenter without a line of its own', function (): void {
    $this->article->update(['author_id' => $this->grace->getKey()]);

    $joined = $this->grace->relationHistory('articles')->get()->sole();

    expect(linesOf($joined))->toHaveCount(1)
        ->and($this->grace->relationHistory('articles')->whereOperation('attach')->get())->toHaveCount(1)
        ->and($this->ada->relationHistory('articles')->whereOperation('attach')->get())->toBeEmpty()
        ->and(Sentinel::audits()->whereRelated($this->article)->get())->toHaveCount(2)
        ->and(presenter()->entry($joined))->toContain('· articles')
        ->and(presenter()->entry($joined))->toContain('+ Article #'.$this->article->getKey());
});
