<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Compliance\Export;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Integrity\Signers;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditAccess;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('writes one line per entry in ndjson, and reads back as the entries it wrote', function (): void {
    foreach (range(1, 3) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    $exported = app(Export::class)->render(Sentinel::audits()->get(), 'ndjson');

    $lines = array_filter(explode("\n", $exported->body));

    expect($lines)->toHaveCount(3)
        ->and($exported->entries)->toBe(3);

    foreach ($lines as $line) {
        expect(json_decode($line, true))->toHaveKey('integrity');
    }
});

it('signs the digest of what it exported, so a recipient can check it without the database', function (): void {
    sentinelConfig(['integrity.signature.enabled' => true]);

    ledger()->write(auditData());

    $exported = app(Export::class)->render(Sentinel::audits()->get(), 'ndjson');

    expect(app(Signers::class)->current()->verify($exported->digest, $exported->signature))->toBeTrue()
        ->and($exported->manifest()['digest'])->toBe($exported->digest)
        ->and($exported->manifest()['entries'])->toBe(1);
});

it('refuses a manifest whose body somebody changed', function (): void {
    sentinelConfig(['integrity.signature.enabled' => true]);

    ledger()->write(auditData());

    $exported = app(Export::class)->render(Sentinel::audits()->get(), 'ndjson');
    $tampered = app(Export::class)->render(Sentinel::audits()->get(), 'json');

    expect($exported->digest)->not->toBe($tampered->digest);
});

it('renders json as one document and csv as a header plus a row each', function (): void {
    foreach (range(1, 2) as $ignored) {
        ledger()->write(auditData());
    }

    $json = app(Export::class)->render(Sentinel::audits()->get(), 'json');
    $csv = app(Export::class)->render(Sentinel::audits()->get(), 'csv');

    expect(json_decode($json->body, true))->toHaveCount(2)
        ->and(array_filter(explode("\n", $csv->body)))->toHaveCount(3);
});

it('exports a redacted entry as redacted, and not as one that was always empty', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    redactor()->redact($written, 'erasure request');

    $exported = app(Export::class)->render(
        Sentinel::audits()->get(),
        'ndjson',
    );

    expect($exported->body)->toContain('"redacted":{')
        ->and($exported->body)->toContain('erasure request');
});

it('writes the body and its manifest to a disk when asked', function (): void {
    Storage::fake('exports');

    ledger()->write(auditData());

    $this->artisan('sentinel:export', ['--disk' => 'exports', '--path' => 'trail.ndjson'])
        ->expectsOutputToContain('Wrote 1 entries')
        ->assertSuccessful();

    expect(Storage::disk('exports')->exists('trail.ndjson'))->toBeTrue()
        ->and(Storage::disk('exports')->exists('trail.ndjson.manifest.json'))->toBeTrue();

    $manifest = json_decode((string) Storage::disk('exports')->get('trail.ndjson.manifest.json'), true);

    expect($manifest['entries'] ?? null)->toBe(1)
        ->and($manifest['digest'] ?? null)->toStartWith('sha256:');
});

it('refuses a format it does not write', function (): void {
    $this->artisan('sentinel:export', ['--format' => 'parquet'])
        ->expectsOutputToContain('is not one this command writes')
        ->assertExitCode(2);
});

it('narrows with the same query surface the application uses', function (): void {
    ledger()->write(auditData(['tenant_id' => 'acme']));
    ledger()->write(auditData(['tenant_id' => 'other']));

    $exported = app(Export::class)->render(Sentinel::audits()->forTenant('acme')->get(), 'ndjson');

    expect($exported->entries)->toBe(1);
});

it('leaves its own access record behind in compliance mode, because an export is a read', function (): void {
    ledger()->write(auditData());

    sentinelConfig(['compliance' => true]);

    $this->artisan('sentinel:export')->assertSuccessful();

    expect(AuditAccess::query()->count())->toBe(1)
        ->and(Audit::query()->where('audit_type', 'access')->count())->toBe(1);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(ElPandaPe\Sentinel\Console\ExportCommand::class)->getDescription())
        ->toBe('Entrega el rastro a quien no tiene la base de datos');
});

it('narrows by tenant and by type from the command line', function (): void {
    ledger()->write(auditData(['tenant_id' => 'acme', 'audit_type' => 'model']));
    ledger()->write(auditData(['tenant_id' => 'other', 'audit_type' => 'model']));
    ledger()->write(auditData(['tenant_id' => 'acme', 'audit_type' => 'security']));

    Storage::fake('exports');

    $this->artisan('sentinel:export', [
        '--disk' => 'exports',
        '--path' => 'narrowed.ndjson',
        '--tenant' => 'acme',
        '--type' => 'model',
        '--limit' => '10',
    ])->expectsOutputToContain('Wrote 1 entries')->assertSuccessful();
});
