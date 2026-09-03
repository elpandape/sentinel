<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Tests\Fixtures\PromotingResolver;
use ElPandaPe\Sentinel\Tests\Fixtures\SubstituteResolver;
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
        ->and($config->snapshotsIncludeHidden())->toBeTrue()
        ->and($config->integrityEnabled())->toBeFalse()
        ->and($config->checkpointsEnabled())->toBeFalse()
        ->and($config->checkpointsEvery())->toBe(1000)
        ->and($config->complianceEnabled())->toBeFalse()
        ->and($config->retention())->toBeEmpty();
});

it('reads no anchoring at all out of a configuration published before anchors existed', function (): void {
    $config = sentinelConfig(['integrity' => ['enabled' => true]]);

    expect($config->checkpointsEnabled())->toBeFalse()
        ->and($config->checkpointsEvery())->toBe(1000);
});

it('takes the window the configuration names, and never one that would anchor nothing', function (mixed $every, int $window): void {
    expect(sentinelConfig(['integrity.checkpoints.every' => $every])->checkpointsEvery())->toBe($window);
})->with([[100, 100], [1, 1], [0, 1], [-5, 1]]);

it('refuses a window that is not a number of entries', function (): void {
    expect(fn (): int => sentinelConfig(['integrity.checkpoints.every' => 'many'])->checkpointsEvery())
        ->toThrow(ConfigurationException::class, 'integrity.checkpoints.every');
});

it('falls back in code when a published configuration never heard of the fanout', function (): void {
    $config = sentinelConfig(['ledger.ledgers' => []]);

    expect($config->fanoutDestinations())->toBe(['database'])
        ->and($config->fanoutPolicy())->toBe(FanoutPolicy::Strict);
});

it('reads the destinations and the policy the configuration declares', function (): void {
    $config = sentinelConfig([
        'ledger.ledgers.fanout.destinations' => ['database', 'null'],
        'ledger.ledgers.fanout.on_failure' => 'primary',
    ]);

    expect($config->fanoutDestinations())->toBe(['database', 'null'])
        ->and($config->fanoutPolicy())->toBe(FanoutPolicy::Primary);
});

it('refuses a destination list that names nothing to write to', function (mixed $destinations): void {
    expect(fn (): array => sentinelConfig(['ledger.ledgers.fanout.destinations' => $destinations])->fanoutDestinations())
        ->toThrow(ConfigurationException::class, 'ledger.ledgers.fanout.destinations');
})->with([[[]], ['database'], [[null]], [['']]]);

it('refuses a fanout policy it does not know', function (mixed $policy): void {
    expect(fn (): FanoutPolicy => sentinelConfig(['ledger.ledgers.fanout.on_failure' => $policy])->fanoutPolicy())
        ->toThrow(ConfigurationException::class, 'ledger.ledgers.fanout.on_failure');
})->with([['nonesuch'], [1]]);

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

it('reads the configured stream strategy', function (): void {
    expect(sentinelConfig()->streamStrategy())->toBe('tenant');
});

it('reads whether hidden attributes reach the snapshot', function (): void {
    expect(sentinelConfig(['snapshots.include_hidden' => false])->snapshotsIncludeHidden())->toBeFalse();
});

it('rejects a non boolean hidden policy', function (): void {
    sentinelConfig(['snapshots.include_hidden' => 'yes'])->snapshotsIncludeHidden();
})->throws(ConfigurationException::class, 'must be a boolean, string given');

it('falls back to the package defaults when the published config predates the resolvers', function (): void {
    $config = sentinelConfig(['resolvers' => []]);

    expect($config->actorGuard())->toBeNull()
        ->and($config->impersonatorSessionKey())->toBe('impersonated_by')
        ->and($config->tenantUsing())->toBeNull()
        ->and($config->requestIdHeader())->toBe('X-Request-Id')
        ->and($config->apiBoundary())->toBe('api/*')
        ->and($config->commandRedactions())->toBe(['password', 'token', 'secret'])
        ->and($config->redactionMask())->toBe('*')
        ->and($config->resolverClass('actor', SubstituteResolver::class))->toBe(SubstituteResolver::class);
});

it('reads the shipped resolver settings', function (): void {
    $config = sentinelConfig();

    expect($config->impersonatorSessionKey())->toBe('impersonated_by')
        ->and($config->requestIdHeader())->toBe('X-Request-Id')
        ->and($config->apiBoundary())->toBe('api/*')
        ->and($config->commandRedactions())->toBe(['password', 'token', 'secret']);
});

it('takes the resolver the configuration names over the package default', function (): void {
    $config = sentinelConfig(['resolvers.actor.class' => PromotingResolver::class]);

    expect($config->resolverClass('actor', SubstituteResolver::class))->toBe(PromotingResolver::class)
        ->and($config->resolverClass('tenant', SubstituteResolver::class))->toBe(SubstituteResolver::class);
});

it('rejects a resolver that does not implement the contract', function (): void {
    sentinelConfig(['resolvers.actor.class' => Config::class])->resolverClass('actor', SubstituteResolver::class);
})->throws(ConfigurationException::class, 'or a subclass of it');

it('rejects a resolver class that is not a string', function (): void {
    sentinelConfig(['resolvers.actor.class' => 42])->resolverClass('actor', SubstituteResolver::class);
})->throws(ConfigurationException::class, 'a class-string or null');

it('rejects a guard name that is not a string', function (): void {
    sentinelConfig(['resolvers.actor.guard' => 42])->actorGuard();
})->throws(ConfigurationException::class, 'a string or null');

it('reads the guard the configuration names', function (): void {
    expect(sentinelConfig(['resolvers.actor.guard' => 'admin'])->actorGuard())->toBe('admin');
});

it('rejects a session key that is not a non empty string', function (): void {
    sentinelConfig(['resolvers.impersonator.session_key' => ''])->impersonatorSessionKey();
})->throws(ConfigurationException::class, 'a non-empty string or null');

it('takes a closure as the api boundary', function (): void {
    $boundary = static fn (): bool => true;

    expect(sentinelConfig(['resolvers.request.api' => $boundary])->apiBoundary())->toBe($boundary);
});

it('rejects a boundary that is neither a pattern nor a closure', function (): void {
    sentinelConfig(['resolvers.request.api' => 42])->apiBoundary();
})->throws(ConfigurationException::class, 'a route pattern or a closure');

it('takes a closure as the tenant hook', function (): void {
    $using = static fn (): string => 'acme';

    expect(sentinelConfig(['resolvers.tenant.using' => $using])->tenantUsing())->toBe($using);
});

it('rejects a tenant hook that is not a closure', function (): void {
    sentinelConfig(['resolvers.tenant.using' => 'acme'])->tenantUsing();
})->throws(ConfigurationException::class, 'a closure or null');

it('reads the redaction list the configuration names', function (): void {
    expect(sentinelConfig(['resolvers.command.redact' => ['month']])->commandRedactions())->toBe(['month']);
});

it('rejects a redaction list that is not a list', function (): void {
    sentinelConfig(['resolvers.command.redact' => 'password'])->commandRedactions();
})->throws(ConfigurationException::class, 'a list of strings');

it('rejects a redaction entry that is not a string', function (): void {
    sentinelConfig(['resolvers.command.redact' => [42]])->commandRedactions();
})->throws(ConfigurationException::class, 'a list of strings');

it('reads the redaction mask the configuration names', function (): void {
    expect(sentinelConfig(['security.redaction.mask' => '#'])->redactionMask())->toBe('#');
});

it('rejects a mask that is not a string', function (): void {
    sentinelConfig(['security.redaction.mask' => 42])->redactionMask();
})->throws(ConfigurationException::class, 'a string');

it('falls back to the shipped signature defaults when the section is null', function (): void {
    $config = sentinelConfig([
        'integrity.signature.signer' => null,
        'integrity.signature.algorithm' => null,
        'integrity.signature.key_id' => null,
    ]);

    expect($config->signatureDriver())->toBe('hmac')
        ->and($config->signatureAlgorithm())->toBe('sha256')
        ->and($config->signatureKeyId())->toBe('default');
});

it('rejects a signer that is not a string', function (): void {
    sentinelConfig(['integrity.signature.signer' => 42])->signatureDriver();
})->throws(ConfigurationException::class, 'integrity.signature.signer] must be a non-empty string or null');

it('rejects a signature algorithm that is not a string', function (): void {
    sentinelConfig(['integrity.signature.algorithm' => 42])->signatureAlgorithm();
})->throws(ConfigurationException::class, 'integrity.signature.algorithm] must be a string');

it('rejects a signature algorithm php cannot hash with', function (): void {
    sentinelConfig(['integrity.signature.algorithm' => 'enigma'])->signatureAlgorithm();
})->throws(ConfigurationException::class, 'integrity.signature.algorithm] has unknown value [enigma]');

it('rejects a signing key identifier that is not a string', function (): void {
    sentinelConfig(['integrity.signature.key_id' => 42])->signatureKeyId();
})->throws(ConfigurationException::class, 'integrity.signature.key_id] must be a non-empty string or null');

it('rejects a signing ring that is not a map', function (): void {
    sentinelConfig(['integrity.signature.keys' => 'one-key'])->signatureKey('default');
})->throws(ConfigurationException::class, 'integrity.signature.keys] must be a map of key id to key');

it('rejects a signing key that is not a string', function (): void {
    sentinelConfig(['integrity.signature.keys' => ['default' => 42]])->signatureKey('default');
})->throws(ConfigurationException::class, 'integrity.signature.keys.default] must be a non-empty string or null');

it('refuses to derive a signing secret with no application key to derive it from', function (): void {
    config()->set('app.key');

    sentinelConfig()->derivedSigningSecret();
})->throws(ConfigurationException::class, 'integrity.signature.keys.default');

it('reads the trace context defaults the published file ships', function (): void {
    $config = sentinelConfig();

    expect($config->telemetryEnabled())->toBeFalse()
        ->and($config->trustsIncomingTrace())->toBeTrue()
        ->and($config->propagatesTrace())->toBeTrue()
        ->and($config->storesTracestate())->toBeFalse()
        ->and($config->opensRootTrace())->toBeFalse();
});

it('keeps those defaults for a configuration published before the keys existed', function (): void {
    $config = sentinelConfig(['telemetry' => ['enabled' => true]]);

    expect($config->telemetryEnabled())->toBeTrue()
        ->and($config->trustsIncomingTrace())->toBeTrue()
        ->and($config->propagatesTrace())->toBeTrue()
        ->and($config->storesTracestate())->toBeFalse()
        ->and($config->opensRootTrace())->toBeFalse();
});

it('takes the trace context switches the configuration names', function (): void {
    $config = sentinelConfig([
        'telemetry.enabled' => true,
        'telemetry.trust_incoming_header' => false,
        'telemetry.propagate_context' => false,
        'telemetry.store_tracestate' => true,
        'telemetry.root_context' => true,
    ]);

    expect($config->telemetryEnabled())->toBeTrue()
        ->and($config->trustsIncomingTrace())->toBeFalse()
        ->and($config->propagatesTrace())->toBeFalse()
        ->and($config->storesTracestate())->toBeTrue()
        ->and($config->opensRootTrace())->toBeTrue();
});

it('refuses a trace context switch that is not a switch', function (string $key, Closure $read): void {
    expect(fn (): bool => $read(sentinelConfig([$key => 'yes'])))
        ->toThrow(ConfigurationException::class, $key);
})->with([
    ['telemetry.enabled', fn (Config $config): bool => $config->telemetryEnabled()],
    ['telemetry.trust_incoming_header', fn (Config $config): bool => $config->trustsIncomingTrace()],
    ['telemetry.propagate_context', fn (Config $config): bool => $config->propagatesTrace()],
    ['telemetry.store_tracestate', fn (Config $config): bool => $config->storesTracestate()],
    ['telemetry.root_context', fn (Config $config): bool => $config->opensRootTrace()],
]);

it('names the service after the application when the configuration names nobody', function (): void {
    config()->set('app.name', 'billing');

    expect(sentinelConfig(['telemetry.service_name' => null])->serviceName())->toBe('billing');
});

it('takes the service name the configuration gives it', function (): void {
    expect(sentinelConfig(['telemetry.service_name' => 'ledger'])->serviceName())->toBe('ledger');
});

it('names no service when neither the configuration nor the application does', function (): void {
    config()->set('app.name');

    expect(sentinelConfig(['telemetry.service_name' => null])->serviceName())->toBeNull();
});

it('refuses a service name that is not a name', function (): void {
    expect(fn (): ?string => sentinelConfig(['telemetry.service_name' => 42])->serviceName())
        ->toThrow(ConfigurationException::class, 'telemetry.service_name');
});
