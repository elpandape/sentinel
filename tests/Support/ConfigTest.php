<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('reads the defaults shipped with the package', function (): void {
    $config = sentinelConfig();

    expect($config->enabled())->toBeTrue()
        ->and($config->mode())->toBe(Mode::Sync)
        ->and($config->ledger())->toBe('database')
        ->and($config->connection())->toBeNull()
        ->and($config->snapshotsEnabled())->toBeTrue()
        ->and($config->integrityEnabled())->toBeFalse()
        ->and($config->complianceEnabled())->toBeFalse()
        ->and($config->retention())->toBeEmpty();
});

it('prefixes table names', function (): void {
    expect(sentinelConfig()->table('audits'))->toBe('sentinel_audits')
        ->and(sentinelConfig(['tables.prefix' => 'audit_'])->table('checkpoints'))->toBe('audit_checkpoints');
});

it('reads a dedicated connection', function (): void {
    expect(sentinelConfig(['database.connection' => 'audits'])->connection())->toBe('audits');
});

it('resolves severity from the event overrides, falling back to the default', function (): void {
    $config = sentinelConfig();

    expect($config->defaultSeverity(AuditEvent::Deleted))->toBe(Severity::Notice)
        ->and($config->defaultSeverity(AuditEvent::ForceDeleted))->toBe(Severity::Warning)
        ->and($config->defaultSeverity(AuditEvent::Created))->toBe(Severity::Info)
        ->and($config->defaultSeverity('invoice.approved'))->toBe(Severity::Info);
});

it('reads retention policies keyed by logical type', function (): void {
    $config = sentinelConfig(['retention' => ['model:App\Models\User' => '7 years', 'auth' => '90 days']]);

    expect($config->retention())->toBe(['model:App\Models\User' => '7 years', 'auth' => '90 days']);
});

it('rejects a missing key', function (): void {
    sentinelConfig(['enabled' => null])->enabled();
})->throws(ConfigurationException::class, 'sentinel.enabled] is not set');

it('rejects a non boolean flag', function (): void {
    sentinelConfig(['enabled' => 'yes'])->enabled();
})->throws(ConfigurationException::class, 'must be a boolean, string given');

it('rejects a non string value', function (): void {
    sentinelConfig(['tables.prefix' => 10])->table('audits');
})->throws(ConfigurationException::class, 'must be a string, int given');

it('rejects a connection that is neither string nor null', function (): void {
    sentinelConfig(['database.connection' => ['audits']])->connection();
})->throws(ConfigurationException::class, 'must be a string or null, array given');

it('rejects a non array section', function (): void {
    sentinelConfig(['severity.events' => 'critical'])->defaultSeverity(AuditEvent::Created);
})->throws(ConfigurationException::class, 'must be an array, string given');

it('rejects an unknown mode', function (): void {
    sentinelConfig(['mode' => 'eventual'])->mode();
})->throws(ConfigurationException::class, 'Accepted: sync, queue, buffered');

it('rejects a severity override that is not a string', function (): void {
    sentinelConfig(['severity.events' => ['deleted' => 3]])->defaultSeverity(AuditEvent::Deleted);
})->throws(ConfigurationException::class, 'severity.events.deleted] must be a string, int given');

it('rejects an unknown severity override', function (): void {
    sentinelConfig(['severity.events' => ['deleted' => 'fatal']])->defaultSeverity(AuditEvent::Deleted);
})->throws(ConfigurationException::class, 'Accepted: info, notice, warning, critical');

it('rejects a retention policy that is not a string', function (): void {
    sentinelConfig(['retention' => ['auth' => 90]])->retention();
})->throws(ConfigurationException::class, 'must be a map of string to string, int given');

it('falls back to the default model when the override is null', function (): void {
    expect(sentinelConfig(['models.audit' => null])->model('audit', Model::class))->toBe(Model::class);
});

it('falls back to the default model when the section has no entry', function (): void {
    expect(sentinelConfig(['models' => []])->model('audit', Model::class))->toBe(Model::class);
});

it('returns the model override the configuration names', function (): void {
    expect(sentinelConfig(['models.audit' => User::class])->model('audit', Model::class))->toBe(User::class);
});

it('rejects a model override that is not a string', function (): void {
    sentinelConfig(['models.audit' => 42])->model('audit', Model::class);
})->throws(ConfigurationException::class, 'models.audit] must be a class-string or null, int given');

it('rejects a model override whose class does not exist', function (): void {
    sentinelConfig(['models.audit' => 'App\\Nope'])->model('audit', Model::class);
})->throws(ConfigurationException::class, '[App\\Nope] given');

it('rejects a model override that does not extend the default', function (): void {
    sentinelConfig(['models.audit' => Config::class])->model('audit', Model::class);
})->throws(ConfigurationException::class, 'or a subclass of it');

it('reads the configured hash algorithm', function (): void {
    expect(sentinelConfig()->integrityAlgorithm())->toBe('sha256');
});

it('rejects a hash algorithm the runtime does not provide', function (): void {
    expect(fn (): string => sentinelConfig(['integrity.algorithm' => 'nonesuch'])->integrityAlgorithm())
        ->toThrow(ConfigurationException::class, 'nonesuch');
});
