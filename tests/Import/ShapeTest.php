<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ImportException;
use ElPandaPe\Sentinel\Import\Shape;
use ElPandaPe\Sentinel\Tests\Fixtures\PretendOrigin;
use Illuminate\Support\Facades\Schema;

it('passes a table shaped the way the origin says its history is', function (): void {
    Schema::create(PretendOrigin::TABLE, function ($table): void {
        $table->id();
        $table->string('event');
        $table->string('subject_type');
        $table->string('subject_id');
        $table->timestamps();
    });

    app(Shape::class)->verify(new PretendOrigin, PretendOrigin::TABLE, null);
})->throwsNoExceptions();

it('passes a table that carries more than the origin asked for', function (): void {
    Schema::create(PretendOrigin::TABLE, function ($table): void {
        $table->id();
        $table->string('event');
        $table->string('subject_type');
        $table->string('subject_id');
        $table->string('something_the_application_added');
        $table->timestamps();
    });

    app(Shape::class)->verify(new PretendOrigin, PretendOrigin::TABLE, null);
})->throwsNoExceptions();

it('refuses a table that is not there, and says how to point somewhere else', function (): void {
    expect(static fn (): mixed => app(Shape::class)->verify(new PretendOrigin, 'nothing_here', null))
        ->toThrow(ImportException::class, 'There is no table [nothing_here]');
});

it('refuses a table of the right name and the wrong shape, naming what is missing', function (): void {
    Schema::create(PretendOrigin::TABLE, function ($table): void {
        $table->id();
        $table->string('event');
        $table->timestamps();
    });

    expect(static fn (): mixed => app(Shape::class)->verify(new PretendOrigin, PretendOrigin::TABLE, null))
        ->toThrow(ImportException::class, 'subject_type, subject_id');
});

it('says nothing was read, because a shape it half understands is the worst thing to import from', function (): void {
    Schema::create(PretendOrigin::TABLE, function ($table): void {
        $table->id();
        $table->timestamps();
    });

    expect(static fn (): mixed => app(Shape::class)->verify(new PretendOrigin, PretendOrigin::TABLE, null))
        ->toThrow(ImportException::class, 'nothing was read');
});
