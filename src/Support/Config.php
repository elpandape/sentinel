<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Closure;
use ElPandaPe\Sentinel\Contracts\Masker;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Enums\ArchiveCodec;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\FailurePolicy;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Enums\MassMode;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\EncryptionException;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    private const string FANOUT_DESTINATIONS = 'ledger.ledgers.fanout.destinations';

    public function __construct(private Repository $repository) {}

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function mode(): Mode
    {
        return $this->enum('mode', Mode::class);
    }

    /**
     * The connection the settling job is dispatched on, and the queue it waits in. Null on either
     * means the application's own default, which is what an installation that never thought about
     * it should get — and what an installation that gave audits a queue of their own can name.
     */
    public function queueConnection(): ?string
    {
        return $this->nullableString('queue.connection');
    }

    public function queueName(): ?string
    {
        return $this->nullableString('queue.queue');
    }

    /**
     * The driver entries wait in under the buffered mode, and the Redis connection it uses. Every
     * one of these has its default here as well as in the published file: the merge is one level
     * deep, so an installation that published the section before a key existed would otherwise win
     * with a section that does not have it.
     */
    public function bufferStore(): string
    {
        return $this->nullableString('buffer.store') ?? 'redis';
    }

    public function bufferConnection(): ?string
    {
        return $this->nullableString('buffer.connection');
    }

    public function bufferKey(): string
    {
        return $this->nullableString('buffer.key') ?? 'sentinel:buffer';
    }

    /**
     * How many entries may wait before an arrival flushes them, and how long the oldest may wait.
     * Both are floors of one: a size of zero would mean flushing nothing, ever.
     */
    public function bufferSize(): int
    {
        return max(1, $this->integer('buffer.size', 500));
    }

    public function bufferInterval(): int
    {
        return max(1, $this->integer('buffer.flush_interval', 60));
    }

    public function table(string $name): string
    {
        return $this->string('tables.prefix').$this->string("tables.{$name}");
    }

    public function connection(): ?string
    {
        return $this->nullableString('database.connection');
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    public function model(string $name, string $default): string
    {
        $value = $this->repository->get("sentinel.models.{$name}");

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected("models.{$name}", 'a class-string or null', get_debug_type($value));
        }

        if (! class_exists($value) || ! is_a($value, $default, true)) {
            throw ConfigurationException::invalidClass("models.{$name}", $value, $default);
        }

        return $value;
    }

    public function ledger(): string
    {
        return $this->string('ledger.default');
    }

    /**
     * A fanout cannot name a fanout: composing one into itself is a loop with no bottom,
     * and the error worth reading is this one rather than a stack overflow.
     *
     * @return non-empty-list<string>
     */
    public function fanoutDestinations(): array
    {
        $value = $this->repository->get('sentinel.ledger.ledgers.fanout.destinations');

        if ($value === null) {
            return ['database'];
        }

        if (! is_array($value) || $value === []) {
            throw ConfigurationException::expected(self::FANOUT_DESTINATIONS, 'a non-empty list of ledger drivers', get_debug_type($value));
        }

        $destinations = [];

        foreach ($value as $destination) {
            if (! is_string($destination) || $destination === '') {
                throw ConfigurationException::expected(self::FANOUT_DESTINATIONS, 'a non-empty list of ledger drivers', get_debug_type($destination));
            }

            $destinations[] = $destination === 'fanout'
                ? throw ConfigurationException::unknown(self::FANOUT_DESTINATIONS, $destination, 'a driver other than fanout')
                : $destination;
        }

        return $destinations;
    }

    public function fanoutPolicy(): FanoutPolicy
    {
        $value = $this->repository->get('sentinel.ledger.ledgers.fanout.on_failure');

        if ($value === null) {
            return FanoutPolicy::Strict;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected('ledger.ledgers.fanout.on_failure', 'a string or null', get_debug_type($value));
        }

        return FanoutPolicy::tryFrom($value)
            ?? throw ConfigurationException::unknown('ledger.ledgers.fanout.on_failure', $value, $this->accepted(FanoutPolicy::class));
    }

    /**
     * Where a cold batch goes and how its bytes are written. The codec is a name the manifest
     * records, so an unknown one is refused here rather than discovered by whoever tries to read the
     * batch back; null is plain text and a legitimate answer.
     */
    public function archiveDisk(): string
    {
        return $this->nullableString('ledger.ledgers.archive.disk') ?? 'local';
    }

    public function archivePath(): string
    {
        return trim($this->nullableString('ledger.ledgers.archive.path') ?? 'sentinel', '/');
    }

    /**
     * How many entries one batch holds before it is sealed. A floor of one: a batch of zero would
     * mean a driver that accumulates for ever and writes nothing.
     */
    public function archiveBatch(): int
    {
        return max(1, $this->integer('ledger.ledgers.archive.batch', 1000));
    }

    public function archiveCodec(): ?ArchiveCodec
    {
        if ($this->repository->get('sentinel.ledger.ledgers.archive.compress') !== null) {
            throw ConfigurationException::renamedArchiveCodec();
        }

        $value = $this->nullableString('ledger.ledgers.archive.codec');

        return match (true) {
            $value === null || $value === '' => null,
            default => ArchiveCodec::tryFrom($value)
                ?? throw ConfigurationException::unknown('ledger.ledgers.archive.codec', $value, $this->accepted(ArchiveCodec::class)),
        };
    }

    public function defaultSeverity(AuditEvent|string $event): Severity
    {
        $name = $event instanceof AuditEvent ? $event->value : $event;
        $overrides = $this->arrayValue('severity.events');

        if (! array_key_exists($name, $overrides)) {
            return $this->enum('severity.default', Severity::class);
        }

        $value = $overrides[$name];

        if (! is_string($value)) {
            throw ConfigurationException::expected("severity.events.{$name}", 'a string', get_debug_type($value));
        }

        return Severity::tryFrom($value)
            ?? throw ConfigurationException::unknown("severity.events.{$name}", $value, $this->accepted(Severity::class));
    }

    /**
     * Whether an entry waits for the database transaction that produced it. On by default, and
     * not as a performance setting: turning it off is asking the ledger to keep claiming facts a
     * rollback undid.
     */
    public function afterCommit(): bool
    {
        return $this->boolean('transactions.after_commit');
    }

    /**
     * What a failed write does to the request that caused it. Compliance overrules the setting
     * rather than validating it, because the two say different things: the setting is an operator
     * deciding how much a lost entry is allowed to cost, and compliance is a statement that no
     * entry may be lost at all.
     */
    public function writeFailurePolicy(): FailurePolicy
    {
        if ($this->complianceEnabled()) {
            return FailurePolicy::Throw;
        }

        $value = $this->string('on_write_failure');

        return FailurePolicy::tryFrom($value)
            ?? throw ConfigurationException::unknown('on_write_failure', $value, $this->accepted(FailurePolicy::class));
    }

    public function logChannel(): ?string
    {
        return $this->nullableString('log_channel');
    }

    /**
     * The column a transition is about when neither the call nor the model names one. A state
     * change has to say which column moved: it is what the change line is filed under, and
     * without it the entry could not be found by the field it is about.
     */
    public function transitionAttribute(): string
    {
        return $this->string('transitions.attribute');
    }

    /**
     * What a query that asked to be audited writes down. Summary is the default and stays the
     * default: it is the only one of the three whose cost does not grow with the size of the set,
     * and a mode that turns a one-line update into thousands of entries is a decision an
     * application makes for itself rather than one it inherits from a published file.
     */
    public function massMode(): MassMode
    {
        $value = $this->repository->get('sentinel.mass_operations.mode');

        if ($value === null) {
            return MassMode::Summary;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected('mass_operations.mode', 'a string or null', get_debug_type($value));
        }

        return MassMode::tryFrom($value)
            ?? throw ConfigurationException::unknown('mass_operations.mode', $value, $this->accepted(MassMode::class));
    }

    /**
     * How many rows the hybrid mode will describe one by one, and how many values of a long set
     * the criteria keeps. Both are floors of one: a threshold of zero would mean a hybrid that is
     * never anything but a summary, which is the summary mode with another name, and a sample of
     * zero would record that a set was truncated without recording any of it.
     */
    public function massThreshold(): int
    {
        return max(1, $this->integer('mass_operations.threshold', 100));
    }

    public function massSample(): int
    {
        return max(1, $this->integer('mass_operations.sample', 20));
    }

    public function tagsEnabled(): bool
    {
        return $this->boolean('tags.enabled');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function tags(array $declared): array
    {
        return $this->union($declared, 'tags.default', 'labels');
    }

    public function snapshotsEnabled(): bool
    {
        return $this->boolean('snapshots.enabled');
    }

    public function snapshotsIncludeHidden(): bool
    {
        return $this->boolean('snapshots.include_hidden');
    }

    public function integrityAlgorithm(): string
    {
        $algorithm = $this->string('integrity.algorithm');

        return in_array($algorithm, hash_algos(), true)
            ? $algorithm
            : throw ConfigurationException::unknown('integrity.algorithm', $algorithm, implode(', ', hash_algos()));
    }

    /**
     * Whether anchors are emitted at all, and how many entries one covers. Read the way the
     * signature block is read rather than through boolean(): the section is nested, and a published
     * config that predates the key would otherwise win with a section that does not have it.
     *
     * The window is a floor of one. A window of zero would anchor nothing, forever, while reporting
     * that anchoring was switched on.
     */
    public function checkpointsEnabled(): bool
    {
        return $this->repository->get('sentinel.integrity.checkpoints.enabled') === true;
    }

    public function checkpointsEvery(): int
    {
        return max(1, $this->integer('integrity.checkpoints.every', 1000));
    }

    public function signatureEnabled(): bool
    {
        return $this->repository->get('sentinel.integrity.signature.enabled') === true;
    }

    public function signatureDriver(): string
    {
        $value = $this->repository->get('sentinel.integrity.signature.signer');

        return match (true) {
            $value === null => 'hmac',
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected('integrity.signature.signer', 'a non-empty string or null', get_debug_type($value)),
        };
    }

    public function signatureAlgorithm(): string
    {
        $value = $this->repository->get('sentinel.integrity.signature.algorithm') ?? 'sha256';

        if (! is_string($value)) {
            throw ConfigurationException::expected('integrity.signature.algorithm', 'a string', get_debug_type($value));
        }

        return in_array($value, hash_algos(), true)
            ? $value
            : throw ConfigurationException::unknown('integrity.signature.algorithm', $value, implode(', ', hash_algos()));
    }

    public function signatureKeyId(): string
    {
        $value = $this->repository->get('sentinel.integrity.signature.key_id');

        return match (true) {
            $value === null => 'default',
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected('integrity.signature.key_id', 'a non-empty string or null', get_debug_type($value)),
        };
    }

    /**
     * What one identifier verifies with, and nothing about whether it can sign. An identifier the
     * ring does not name reads as null rather than as an exception: a signature nobody can resolve
     * is a report state, not a crash, and the verification has to be able to say so.
     */
    public function signatureKey(string $keyId): ?string
    {
        $keys = $this->repository->get('sentinel.integrity.signature.keys');

        if ($keys !== null && ! is_array($keys)) {
            throw ConfigurationException::expected('integrity.signature.keys', 'a map of key id to key', get_debug_type($keys));
        }

        $key = is_array($keys) ? $keys[$keyId] ?? null : null;

        return match (true) {
            is_string($key) && $key !== '' => $key,
            $key === null => null,
            default => throw ConfigurationException::expected("integrity.signature.keys.{$keyId}", 'a non-empty string or null', get_debug_type($key)),
        };
    }

    public function signaturePrivateKey(): ?string
    {
        return $this->nullableString('integrity.signature.private_key');
    }

    /**
     * The secret an unnamed HMAC key falls back to. Derived from the application key with a label
     * of its own, so the signing secret is not the same bytes as the digest salt: one leaking must
     * not hand over the other.
     */
    public function derivedSigningSecret(): string
    {
        $key = $this->repository->get('app.key');

        return is_string($key) && $key !== ''
            ? hash_hmac('sha256', 'sentinel:signature', $key)
            : throw ConfigurationException::missingApplicationKey('integrity.signature.keys.default');
    }

    public function streamStrategy(): string|Closure
    {
        $value = $this->value('integrity.stream');

        return is_string($value) || $value instanceof Closure
            ? $value
            : throw ConfigurationException::expected('integrity.stream', 'a string or a closure', get_debug_type($value));
    }

    public function complianceEnabled(): bool
    {
        return $this->boolean('compliance');
    }

    /**
     * @return array<string, string>
     */
    public function retention(): array
    {
        $policies = [];

        foreach ($this->arrayValue('retention') as $target => $period) {
            if (! is_string($target) || ! is_string($period)) {
                throw ConfigurationException::expected('retention', 'a map of string to string', get_debug_type($period));
            }

            $policies[$target] = $period;
        }

        return $policies;
    }

    /**
     * How many anchored ranges one run of the purge will look at. A floor of one: zero would mean a
     * run that reports it looked and looked at nothing.
     */
    public function pruneWindows(): int
    {
        return max(1, $this->integer('prune.windows', 100));
    }

    /**
     * How many entries one statement removes, and how long the purge waits between two of them. The
     * batch is a floor of one; the pause is a floor of zero, because not waiting is a legitimate
     * answer and the only one an idle installation needs.
     */
    public function pruneBatch(): int
    {
        return max(1, $this->integer('prune.batch', 1000));
    }

    public function prunePause(): int
    {
        return max(0, $this->integer('prune.pause', 0));
    }

    /**
     * The defaults live here and not only in the publishable file because the config merge is
     * shallow: an installation that published sentinel.php while `resolvers` was still an empty
     * array would otherwise override the whole subtree and end up with no resolvers at all.
     *
     * @template TResolver of Resolver
     *
     * @param  class-string<TResolver>  $default
     * @return class-string<TResolver>
     */
    public function resolverClass(string $name, string $default): string
    {
        $value = $this->repository->get("sentinel.resolvers.{$name}.class");

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected("resolvers.{$name}.class", 'a class-string or null', get_debug_type($value));
        }

        if (! class_exists($value) || ! is_a($value, Resolver::class, true)) {
            throw ConfigurationException::invalidClass("resolvers.{$name}.class", $value, Resolver::class);
        }

        /** @var class-string<TResolver> $value */
        return $value;
    }

    /**
     * An empty list falls back to the package order rather than to no pipeline at all, so a
     * shallow merge over an empty declaration cannot leave an installation transforming nothing.
     * A non-empty list is taken verbatim: dropping a stage means declaring the list without it.
     *
     * The consequence runs the other way too, and it is the one that bites. The published config
     * names every stage, so an installation that published it is pinned to the list it published:
     * a stage this package adds later will not run there until the list names it. Any version that
     * adds one says so in UPGRADE.md.
     *
     * @param  list<class-string<Transformer>>  $default
     * @return list<class-string<Transformer>>
     */
    public function pipelineStages(array $default): array
    {
        $value = $this->repository->get('sentinel.pipeline');

        if ($value === null || $value === []) {
            return $default;
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected('pipeline', 'a list of stage class-strings', get_debug_type($value));
        }

        $stages = [];

        foreach ($value as $stage) {
            if (! is_string($stage)) {
                throw ConfigurationException::expected('pipeline', 'a list of stage class-strings', get_debug_type($stage));
            }

            if (! class_exists($stage) || ! is_a($stage, Transformer::class, true)) {
                throw ConfigurationException::invalidClass('pipeline', $stage, Transformer::class);
            }

            $stages[] = $stage;
        }

        return $stages;
    }

    public function actorGuard(): ?string
    {
        $value = $this->repository->get('sentinel.resolvers.actor.guard');

        return $value === null || is_string($value)
            ? $value
            : throw ConfigurationException::expected('resolvers.actor.guard', 'a string or null', get_debug_type($value));
    }

    public function impersonatorSessionKey(): string
    {
        return $this->resolverString('impersonator.session_key', 'impersonated_by');
    }

    public function tenantUsing(): ?Closure
    {
        $value = $this->repository->get('sentinel.resolvers.tenant.using');

        return $value === null || $value instanceof Closure
            ? $value
            : throw ConfigurationException::expected('resolvers.tenant.using', 'a closure or null', get_debug_type($value));
    }

    public function requestIdHeader(): string
    {
        return $this->resolverString('request.header', 'X-Request-Id');
    }

    public function apiBoundary(): string|Closure
    {
        $value = $this->repository->get('sentinel.resolvers.request.api');

        return match (true) {
            $value === null => 'api/*',
            is_string($value) && $value !== '' => $value,
            $value instanceof Closure => $value,
            default => throw ConfigurationException::expected('resolvers.request.api', 'a route pattern or a closure', get_debug_type($value)),
        };
    }

    /**
     * @return list<string>
     */
    public function commandRedactions(): array
    {
        $value = $this->repository->get('sentinel.resolvers.command.redact');

        if ($value === null) {
            return ['password', 'token', 'secret'];
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected('resolvers.command.redact', 'a list of strings', get_debug_type($value));
        }

        $needles = [];

        foreach ($value as $needle) {
            $needles[] = is_string($needle)
                ? $needle
                : throw ConfigurationException::expected('resolvers.command.redact', 'a list of strings', get_debug_type($needle));
        }

        return $needles;
    }

    /**
     * The declared list and the configured one are a union, not a fallback. The config list
     * is the only way to name a key that no model owns — an address, a session id, a console
     * argument — so it has to apply to entries a model never declared anything about.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function redactedFields(array $declared): array
    {
        return $this->union($declared, 'security.redaction.fields');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function hashedFields(array $declared): array
    {
        return $this->union($declared, 'security.hashing.fields');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function encryptedFields(array $declared): array
    {
        return $this->union($declared, 'security.encryption.fields');
    }

    public function encryptionKeyId(): string
    {
        $value = $this->repository->get('sentinel.security.encryption.key_id');

        return match (true) {
            $value === null => 'default',
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected('security.encryption.key_id', 'a non-empty string or null', get_debug_type($value)),
        };
    }

    public function encryptionCipher(): string
    {
        $value = $this->repository->get('sentinel.security.encryption.cipher');

        return match (true) {
            $value === null => 'aes-256-gcm',
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected('security.encryption.cipher', 'a cipher name or null', get_debug_type($value)),
        };
    }

    /**
     * The application key is the fallback for the default identifier only. Any other one is
     * named on purpose, and silently writing it with a key it did not name would make the
     * key_id recorded in the entry a lie.
     */
    public function encryptionKey(string $keyId): string
    {
        $keys = $this->repository->get('sentinel.security.encryption.keys');

        if ($keys !== null && ! is_array($keys)) {
            throw ConfigurationException::expected('security.encryption.keys', 'a map of key id to key', get_debug_type($keys));
        }

        $key = is_array($keys) ? $keys[$keyId] ?? null : null;

        if (is_string($key) && $key !== '') {
            return $key;
        }

        if ($key !== null) {
            throw ConfigurationException::expected("security.encryption.keys.{$keyId}", 'a non-empty string or null', get_debug_type($key));
        }

        if ($keyId !== 'default') {
            throw EncryptionException::unknownKey($keyId);
        }

        $application = $this->repository->get('app.key');

        return is_string($application) && $application !== ''
            ? $application
            : throw ConfigurationException::missingApplicationKey('security.encryption.keys.default');
    }

    /**
     * @param  class-string<Masker>  $default
     * @return class-string<Masker>
     */
    public function maskerClass(string $field, string $default): string
    {
        foreach (["security.redaction.maskers.{$field}", 'security.redaction.masker'] as $key) {
            $value = $this->repository->get("sentinel.{$key}");

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw ConfigurationException::expected($key, 'a class-string or null', get_debug_type($value));
            }

            if (! class_exists($value) || ! is_a($value, Masker::class, true)) {
                throw ConfigurationException::invalidClass($key, $value, Masker::class);
            }

            /** @var class-string<Masker> $value */
            return $value;
        }

        return $default;
    }

    public function hashingAlgorithm(): string
    {
        $algorithm = $this->repository->get('sentinel.security.hashing.algorithm') ?? 'sha256';

        if (! is_string($algorithm)) {
            throw ConfigurationException::expected('security.hashing.algorithm', 'a string', get_debug_type($algorithm));
        }

        return in_array($algorithm, hash_algos(), true)
            ? $algorithm
            : throw ConfigurationException::unknown('security.hashing.algorithm', $algorithm, implode(', ', hash_algos()));
    }

    /**
     * Derived from the application key when none is declared, so a digest is comparable
     * across every entry of one installation and across none of two.
     */
    public function hashingSalt(): string
    {
        $salt = $this->repository->get('sentinel.security.hashing.salt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        if ($salt !== null && $salt !== '') {
            throw ConfigurationException::expected('security.hashing.salt', 'a non-empty string or null', get_debug_type($salt));
        }

        $key = $this->repository->get('app.key');

        return is_string($key) && $key !== ''
            ? hash_hmac('sha256', 'sentinel:hashing', $key)
            : throw ConfigurationException::missingApplicationKey('security.hashing.salt');
    }

    public function redactionMask(): string
    {
        $value = $this->repository->get('sentinel.security.redaction.mask');

        return match (true) {
            $value === null || $value === '' => '*',
            is_string($value) => $value,
            default => throw ConfigurationException::expected('security.redaction.mask', 'a string', get_debug_type($value)),
        };
    }

    /**
     * Whether the package resolves a trace at all. Off means no header is read, no provider is
     * asked and no envelope is written, so an installation that does not run distributed tracing
     * pays nothing for the machinery that would serve one.
     */
    public function telemetryEnabled(): bool
    {
        return $this->flag('telemetry.enabled', false);
    }

    /**
     * What this service calls itself inside a trace. It lands in `context` beside hostname and
     * environment, because the schema has no column for it and §5.1 closed in v0.2.0.
     */
    public function serviceName(): ?string
    {
        $name = $this->nullableString('telemetry.service_name');

        if ($name !== null) {
            return $name;
        }

        $application = $this->repository->get('app.name');

        return is_string($application) ? $application : null;
    }

    /**
     * Whether an incoming traceparent is believed. It is a value the caller chooses, so at a public
     * edge this is what you turn off: a third party can otherwise pick the trace its entries are
     * filed under, and trace_id is indexed.
     */
    public function trustsIncomingTrace(): bool
    {
        return $this->flag('telemetry.trust_incoming_header', true);
    }

    public function propagatesTrace(): bool
    {
        return $this->flag('telemetry.propagate_context', true);
    }

    public function storesTracestate(): bool
    {
        return $this->flag('telemetry.store_tracestate', false);
    }

    /**
     * Whether a run that nobody traced opens a trace of its own, so every entry a command or a
     * scheduled task writes shares one trace_id.
     */
    public function opensRootTrace(): bool
    {
        return $this->flag('telemetry.root_context', false);
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    private function union(array $declared, string $key, string $of = 'field names'): array
    {
        $value = $this->repository->get("sentinel.{$key}");

        if ($value === null) {
            return $declared;
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected($key, "a list of {$of}", get_debug_type($value));
        }

        $fields = $declared;

        foreach ($value as $field) {
            $fields[] = is_string($field)
                ? $field
                : throw ConfigurationException::expected($key, "a list of {$of}", get_debug_type($field));
        }

        return array_values(array_unique($fields));
    }

    private function resolverString(string $key, string $default): string
    {
        $value = $this->repository->get("sentinel.resolvers.{$key}");

        return match (true) {
            $value === null => $default,
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected("resolvers.{$key}", 'a non-empty string or null', get_debug_type($value)),
        };
    }

    /**
     * A boolean whose default lives here as well as in the published file: the merge is one level
     * deep, so a config published before the key existed would otherwise win with a section that
     * does not have it. Anything that is neither a boolean nor absent is a mistake worth a throw.
     */
    private function flag(string $key, bool $default): bool
    {
        $value = $this->repository->get("sentinel.{$key}");

        return match (true) {
            $value === null => $default,
            is_bool($value) => $value,
            default => throw ConfigurationException::expected($key, 'a boolean or null', get_debug_type($value)),
        };
    }

    private function boolean(string $key): bool
    {
        $value = $this->value($key);

        return is_bool($value)
            ? $value
            : throw ConfigurationException::expected($key, 'a boolean', get_debug_type($value));
    }

    private function string(string $key): string
    {
        $value = $this->value($key);

        return is_string($value)
            ? $value
            : throw ConfigurationException::expected($key, 'a string', get_debug_type($value));
    }

    private function integer(string $key, int $default): int
    {
        $value = $this->repository->get("sentinel.{$key}");

        return match (true) {
            $value === null => $default,
            is_int($value) => $value,
            default => throw ConfigurationException::expected($key, 'an integer or null', get_debug_type($value)),
        };
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->repository->get("sentinel.{$key}");

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw ConfigurationException::expected($key, 'a string or null', get_debug_type($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayValue(string $key): array
    {
        $value = $this->value($key);

        return is_array($value)
            ? $value
            : throw ConfigurationException::expected($key, 'an array', get_debug_type($value));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $key, string $enum): object
    {
        $value = $this->string($key);

        return $enum::tryFrom($value)
            ?? throw ConfigurationException::unknown($key, $value, $this->accepted($enum));
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function accepted(string $enum): string
    {
        return implode(', ', array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        ));
    }

    private function value(string $key): mixed
    {
        $value = $this->repository->get("sentinel.{$key}");

        return $value ?? throw ConfigurationException::missing($key);
    }
}
