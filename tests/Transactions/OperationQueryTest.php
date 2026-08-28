<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

it('walks from an entry to the operation that produced it', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
    });

    $entry = Audit::query()->firstOrFail();

    expect($entry->transaction)->toBeInstanceOf(AuditTransaction::class)
        ->and($entry->transaction->name)->toBe('invoice-payment');
});

it('resolves no operation for an entry that belongs to none', function (): void {
    AuditedSubject::query()->create(['name' => 'lone']);

    expect(Audit::query()->firstOrFail()->transaction)->toBeNull();
});

it('narrows the trail to an operation handed in whole', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
        AuditedSubject::query()->create(['name' => 'payment']);
    });

    AuditedSubject::query()->create(['name' => 'unrelated']);

    $header = AuditTransaction::query()->firstOrFail();

    expect(Sentinel::audits()->inTransaction($header)->get())->toHaveCount(2);
});

it('narrows the same way when handed only the identifier', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
    });

    AuditedSubject::query()->create(['name' => 'unrelated']);

    $header = AuditTransaction::query()->firstOrFail();

    expect(Sentinel::audits()->inTransaction($header->id)->get())
        ->toHaveCount(Sentinel::audits()->inTransaction($header)->get()->count());
});

it('reads an operation and its entries as one thing', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        AuditedSubject::query()->create(['name' => 'invoice']);
        AuditedSubject::query()->create(['name' => 'payment']);
    });

    $header = AuditTransaction::query()->firstOrFail();

    expect($header->audits)->toHaveCount(2)
        ->and($header->audits_count)->toBe($header->audits->count())
        ->and($header->audits->pluck('transaction_id')->unique()->all())->toBe([$header->id]);
});
