# 📚 Configuration

> Every key in `config/sentinel.php`, in the order the file declares them, plus the three rules that
> govern all of them: where defaults come from, which subtrees take no options, and what happens
> when a value is wrong.

**On this page:** [Getting the file](#getting-the-file) · [Every key](#every-key-in-file-order) · [Environment variables](#environment-variables) · [Three rules for every key](#three-rules-that-apply-to-every-key) · [Three configurations](#three-configurations) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## Getting the file

`php artisan sentinel:install` copies `config/sentinel.php` into the application if it is not already
there, and leaves it exactly as it is if it is — edits included. It never overwrites, which is why
running it twice is the ordinary case rather than the careless one: the second run reports the
configuration as present and tells you which tables are still missing. `vendor:publish
--tag=sentinel-config` publishes the same file.

You do not have to publish anything. The package merges its own file into the application's
configuration at registration, so an unpublished installation runs on the defaults documented below.

Nothing in `src/` calls `config('sentinel.*')`. Every read goes through `ElPandaPe\Sentinel\Support\Config`,
a typed reader over `Illuminate\Contracts\Config\Repository` with roughly seventy accessors — one per key
or key group. It caches nothing: each accessor re-reads the repository and re-validates, so
`config()->set('sentinel.…')` takes effect on the next write, and a bad value planted at runtime
surfaces at the moment something asks for it.

> 📌 **Note.** `env()` is only consulted while the configuration is uncached. After `php artisan
> config:cache`, the values baked into the cache are what the application runs on — the standard
> Laravel rule, and it applies to every `SENTINEL_*` variable below.

---

## Every key, in file order

Each table gives the full dotted path, the type `Support\Config` demands, the default, and what the
key does. Where a key's default also lives in code (see [Three rules](#three-rules-that-apply-to-every-key)),
the Default column says so with **(code)**.

### Recording and dispatch

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `enabled` | bool | `true` (env) | Read by `Sentinel::isRecording()`; `false` makes every capture path a no-op. Turn it off wholesale for a data-migration environment — for one operation, use `Sentinel::withoutAuditing()` instead. |
| `mode` | string | `sync` (env) | Where an entry settles: `sync` in the request, `queue` in a worker, `buffered` in a batched flush. Resolved per entry, so it may change between two writes. Anything else raises a `ConfigurationException` naming `sync, queue, buffered`. |
| `queue.connection` | ?string | `null` (env, **code**) | The connection `Jobs\SettleAudit` is dispatched on. `null` is the application default. Name one when audits must not share a backend with the rest of the application's jobs. |
| `queue.queue` | ?string | `null` (env, **code**) | The queue the job waits in. Give audits their own when the default queue holds slow work — an audit behind a video transcode arrives long after the fact it describes. |
| `buffer.store` | string | `redis` (env, **code**) | `redis` (a Redis list) or `memory` (everything on the instance — a reference implementation and a test double, never a store). An unknown value throws naming `redis, memory` rather than falling back. |
| `buffer.connection` | ?string | `null` (env, **code**) | Which `database.redis.*` connection the buffer opens. Point it at a Redis whose persistence and eviction policy you chose. |
| `buffer.key` | string | `sentinel:buffer` (**code**) | The Redis list key. Change it so two applications sharing one Redis do not share a buffer; never share it with anything else. |
| `buffer.size` | int | `500` (**code**) | Three things at once: the flush threshold on push, the number of entries one take removes, and therefore the batch size. Floored at 1 — a configured `0` flushes on every entry, it does not mean "never". |
| `buffer.flush_interval` | int seconds | `60` (**code**) | Flush when the oldest waiting entry's `occurred_at` plus this interval is in the past. Floored at 1. Evaluated only when an entry arrives: nothing in PHP watches a clock between requests. |

**Explained in:** [Performance modes](../09-operations/01-performance-modes.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Running audits on a queue](../09-operations/03-queues.md)

### What a failed write costs

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `on_write_failure` | string | `throw` (env) | `throw` propagates the failure to the request that caused it; `log` records it through the channel below and lets the request through. One default and not one per environment. Compliance mode overrules it to `throw` regardless. |
| `log_channel` | ?string | `null` (env, **code**) | The channel a recorded failure is written through. `null` uses the application default. Route audit-write failures to a channel somebody is actually alerted on, especially under `log`. |

> ⚠️ **Warning.** `on_write_failure` governs only the write that happens inside the request. A write
> deferred to a commit cannot propagate anything — by then the transaction has committed — so a
> deferred failure is always announced and recorded instead of thrown.

**Explained in:** [Failure policy](../09-operations/05-failure-policy.md)

### Where entries are stored

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `ledger.default` | string | `database` (env) | Which `Contracts\Ledger` the container resolves: `database`, `memory`, `null` or `fanout`. `archive` is refused here by name. Use `null` to keep the capture path running while writing nothing; `fanout` to mirror one entry to several destinations. |
| `ledger.ledgers.database` | array | `[]` | Ships empty and takes no options. See [empty driver subtrees](#an-empty-driver-subtree-takes-no-options). |
| `ledger.ledgers.archive.disk` | string | `local` (env, **code**) | The `Storage` disk a cold batch is written to. A batch is always *read* from the disk its manifest row names, so moving this later leaves old batches readable. |
| `ledger.ledgers.archive.path` | string | `sentinel` (**code**) | Root prefix on that disk, trimmed of slashes. A prefix long enough to push the full object key past 512 characters raises `ConfigurationException::archivePathTooLong`. |
| `ledger.ledgers.archive.codec` | ?string | `gzip` in the file, `null` in code | How the bytes are written: `gzip` (needs `ext-zlib`) or `null`/`''` for plain NDJSON. It is a name and not a flag because the manifest records it — a boolean could never say what to inflate a batch written two years ago with. This is the one `archive` key whose code fallback differs from the shipped file: a published `archive` subtree with no `codec` in it writes plain NDJSON, not gzip. |
| `ledger.ledgers.archive.batch` | int | `1000` (**code**) | How many entries the *archive driver* accumulates before sealing a file. It does not bound what `sentinel:prune` writes — the prune writes one file per anchor window. Floored at 1. |
| `ledger.ledgers.memory` | array | `[]` | Ships empty and takes no options. |
| `ledger.ledgers.null` | array | `[]` | Ships empty and takes no options. |
| `ledger.ledgers.fanout.destinations` | non-empty list\<string\> | `['database']` (**code**) | One entry, several destinations. The **first is the primary**: it assigns the sequence and seals the hash, and the rest are handed what it sealed. `fanout` inside the list is refused — composing one into itself is a loop with no bottom. |
| `ledger.ledgers.fanout.on_failure` | string | `strict` (**code**) | Under `strict` any destination refusing the entry fails the write; under `primary` only the first one does, and the rest raise `LedgerDestinationFailed`. |
| `database.connection` | ?string | `null` (env) | A dedicated connection for the audit tables. `null` uses the application default. Isolating audit writes also decouples them from the application's transaction — which is what `transactions.after_commit` exists to handle. |
| `tables.prefix` | string | `sentinel_` | Prefixed to every table name. `Config::table('audits')` returns prefix + `tables.audits`; nothing in the package writes a table name literally. Change it only before there is data. |
| `tables.audits` · `audit_tags` · `audit_relations` · `transactions` · `checkpoints` · `archives` · `access_log` | string | `audits`, `audit_tags`, `audit_relations`, `transactions`, `checkpoints`, `archives`, `access_log` | One key per table. All seven are created by the shipped migrations; only `sentinel_access_log` stays empty until compliance mode, so turning that on is configuration and not a migration. |
| `models.audit` | ?class-string | `null` → `Models\Audit` (**code**) | Replaces the entry model the container and `$model->audits()` resolve. Must be a subclass of `Models\Audit` — anything else raises `ConfigurationException::invalidClass`. |
| `models.transaction` | ?class-string | `null` → `Models\AuditTransaction` (**code**) | The same for the business-transaction header model. |

**Explained in:** [The shipped drivers](../11-extending/02-shipped-drivers.md) · [Fanout](../11-extending/05-fanout.md) · [Schema](03-schema.md) · [Swapping components](../11-extending/06-swapping-components.md)

### Context resolvers

Ten resolvers, one entry each. `class` set to `null` means the package default; any class implementing
`Contracts\Resolver` replaces it. Every key in this section carries its default in code.

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `resolvers.actor.class` … `resolvers.command.class` | ?class-string | `null` → the shipped resolver | One per resolver: `actor`, `impersonator`, `tenant`, `request`, `session`, `trace`, `source`, `host`, `job`, `command`. A class that does not exist or does not implement `Contracts\Resolver` raises `ConfigurationException::invalidClass`. |
| `resolvers.actor.guard` | ?string | `null` | The auth guard the actor (and the impersonated user) is read from. `null` uses the application's default guard. |
| `resolvers.impersonator.session_key` | non-empty string | `impersonated_by` | The session key holding the impersonator's identifier — the convention `lab404/laravel-impersonate` established. Change it if your impersonation layer uses another name. |
| `resolvers.tenant.using` | ?Closure | `null` | A closure returning the current tenant identifier. A closure cannot survive `config:cache`; a resolver class can, and is the form to reach for if you cache configuration. |
| `resolvers.request.header` | non-empty string | `X-Request-Id` | The header the opt-in `Http\Middleware\AssignRequestId` reads an inbound correlation id from and echoes back. It is read by that middleware only — the resolver mints a ULID when nothing latched one. |
| `resolvers.request.api` | string pattern \| Closure | `api/*` | Where the API boundary is, which is what decides `source = api` rather than `http`. A closure receives the request and must return a boolean; anything else raises `ConfigurationException::expected`. |
| `resolvers.command.redact` | list\<string\> | `['password', 'token', 'secret']` | Substrings of a console argument or option name whose value is masked before it reaches the entry. `context` carries the command line, so this is the list that keeps a secret off the trail. |

**Explained in:** [The ten resolvers](../04-context/02-resolvers-reference.md) · [Actor and impersonation](../04-context/03-actor-and-impersonation.md) · [Multi-tenancy](../04-context/04-multi-tenancy.md) · [Writing your own resolver](../04-context/07-writing-your-own-resolver.md)

### The pipeline and what an entry keeps

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `pipeline` | list\<class-string\<Transformer\>\> | the seven shipped stages | The stage order every entry goes through: `FilterUnchanged`, `ResolveContext`, `ResolveTags`, `NormalizeData`, `MaskSensitiveData`, `EncryptSensitiveData`, `EnforcePolicies`. `null` or `[]` falls back to that list; a non-empty list is taken **verbatim**. Declare the full list to insert your own stage or to drop one. |
| `snapshots.enabled` | bool | `true` | ANDed with the model's `$auditSnapshots`. `false` drops `before`/`after` from every entry — the entry, the chain and the diff all survive, so this saves storage and not time. |
| `snapshots.include_hidden` | bool | `true` | Hidden attributes are audited by default, because auditing is what the package is for. `false` drops the model's `$hidden` from every snapshot — but only where no `$auditInclude` is declared, since a declared include list wins outright. |

> ⚠️ **Warning.** `pipeline` is the one section the shallow config merge cannot rescue. The published
> file names every stage, so an installation that published it is pinned to the list it published: a
> stage a later version adds will not run there until the list names it. Re-read this key against
> `UPGRADE.md` on every upgrade.

**Explained in:** [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Snapshots](../03-capture/02-snapshots.md)

### Protecting values

A model declares its own fields with `$auditRedact`, `$auditEncrypt` and `$auditHash`. The three
`fields` lists here are a **union** with those, never a replacement — and they are the only way to
name a key no model owns, such as an IP address, a session id or a console argument.

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `security.redaction.mask` | string | `*` (**code**) | The character the default masker repeats. An empty string reads as `*`. |
| `security.redaction.fields` | list\<string\> | `[]` | Field names masked on top of every model's `$auditRedact`. |
| `security.redaction.masker` | ?class-string\<Masker\> | `null` → `Security\PartialMasker` | The default masker for every field. |
| `security.redaction.maskers` | map\<string, class-string\<Masker\>\> | `[]` | Per-field overrides, keyed by field name. A per-field entry wins over `masker`, which wins over the package default. |
| `security.encryption.cipher` | non-empty string | `aes-256-gcm` (**code**) | The cipher the keyring builds each `Illuminate\Encryption\Encrypter` with. A cipher the encrypter refuses raises `EncryptionException::unusableKey` naming the key and the cipher. |
| `security.encryption.key_id` | non-empty string | `default` (**code**) | The identifier every new entry is written with. Older entries keep the identifier they recorded, so rotating this leaves them readable as long as their key stays on the ring. |
| `security.encryption.keys` | map\<string, string\> | `['default' => env]` | Identifier → key. The application key is the fallback for `default` **and for no other identifier**: naming one the ring does not hold raises `EncryptionException::unknownKey`, because silently writing it with a key it did not name would make the recorded `key_id` a lie. |
| `security.encryption.fields` | list\<string\> | `[]` | Fields encrypted on top of every model's `$auditEncrypt`. |
| `security.hashing.algorithm` | string | `sha256` (**code**) | The digest used for hashed fields. Validated against `hash_algos()`. |
| `security.hashing.salt` | ?string | `null` (env, **code**) | Derived from `APP_KEY` under its own label when null, so a digest is comparable across every entry of one installation and across none of two. Stable by definition: rotating it breaks no chain and destroys the comparability of every digest written before it. |
| `security.hashing.fields` | list\<string\> | `[]` | Fields replaced with a salted digest on top of every model's `$auditHash`. |

**Explained in:** [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) · [Writing a masker](../05-pipeline-and-security/05-writing-a-masker.md)

### The chain

Chaining itself has no switch. What is optional — and ships off — is signing and anchoring.

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `integrity.algorithm` | string | `sha256` | The digest every **new** entry is sealed with, stamped into the row's `algorithm` column and read back from there on verification, never from configuration. Validated only against `hash_algos()`. |
| `integrity.stream` | string \| Closure | `tenant` | How a chain is scoped: `global`, `tenant` (`tenant:<id>`, falling back to `global`), `subject_type` (`type:<morph alias>`, falling back to `global`), a closure returning a name, or a class-string implementing `Contracts\StreamResolver`. The resolved name is capped at 64 characters and never truncated. |
| `integrity.checkpoints.enabled` | bool | `false` (**code**) | Whether the ledger anchors on the write path, after the sealing transaction commits. It does **not** gate `sentinel:checkpoint`, which anchors either way. |
| `integrity.checkpoints.every` | int | `1000` (**code**) | The fixed window one anchor covers. Floored at 1. Existing anchors keep their own width; raising it stalls emission until the wider window fills. |
| `integrity.signature.enabled` | bool | `false` (**code**) | Whether entries and anchor roots are signed. Not retroactive: prior history stays unsigned, and that is a sound state rather than a finding. |
| `integrity.signature.signer` | non-empty string | `hmac` (**code**) | `hmac`, `openssl` or `null`. Anything else raises `ConfigurationException::unknown`. |
| `integrity.signature.algorithm` | string | `sha256` (**code**) | The MAC digest under `hmac`, the signature digest under `openssl`. Validated against `hash_algos()` — which is a longer list than OpenSSL will sign with, so a value that passes here can still fail on the first signed write. |
| `integrity.signature.key_id` | non-empty string | `default` (**code**) | The identifier every new signature records, and under `openssl` the one identifier the ring hands the private key to. Rotation is moving this and leaving the old key in `keys`. |
| `integrity.signature.keys` | map\<string, ?string\> | `['default' => env]` | What each identifier **verifies** with: the shared secret under `hmac`, the public key under `openssl`. This is the half an external auditor is handed. An identifier the ring cannot resolve reports `unknown_key`, never `invalid`. Under `hmac`, `default` left null is derived from `APP_KEY` under its own label. |
| `integrity.signature.private_key` | ?string | `null` (env, **code**) | Under `openssl` only: what the current identifier **signs** with. Leave it unset on a node that only verifies — `sign()` then throws `SignatureException::verifyOnly` rather than signing with something it should not have. |

> 🔒 **Security.** Keep the signing secret and the digest salt distinct. Both derive from `APP_KEY`
> when null, but under different labels, so one leaking does not hand over the other. Reusing
> `security.encryption.keys` as `integrity.signature.keys` collapses that separation.

**Explained in:** [The hash chain](../07-integrity/01-the-hash-chain.md) · [Streams](../07-integrity/02-streams.md) · [Signing the chain](../07-integrity/04-signing.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md)

### Grading, labelling and correlating

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `severity.default` | string | `info` | The grade for any event with no override. Beaten per event by `severity.events`, and both are beaten by a model's `$auditSeverity`. Accepted: `info`, `notice`, `warning`, `critical`. |
| `severity.events` | map\<string, string\> | `deleted: notice`, `force_deleted: warning`, `rekeyed: notice`, `failed: warning`, `lockout: critical`, `password_reset: notice` | Per-event grade, looked up by the event's own name — including the authentication events, which are not cases of `Enums\AuditEvent`. An unknown severity throws naming the exact key. |
| `tags.enabled` | bool | `true` | `false` makes the `ResolveTags` stage leave labels alone entirely. |
| `tags.default` | list\<string\> | `[]` | Labels every entry is born with, unioned with the model's `$auditTags` and whatever the caller set. Any label over 64 characters raises `ConfigurationException::tagTooLong` — a label is a whole word or it is not the label that was meant. |
| `transitions.attribute` | string | `status` | The column a state change is about when neither the call nor the model names one. |
| `transactions.after_commit` | bool | `true` | Defers the hand-over to `Connection::afterCommit()` when the subject's connection has a transaction open — in every mode — so a rollback leaves no record of what never happened. Not a performance setting: turning it off asks the ledger to keep claiming facts a rollback undid. |

**Explained in:** [Enums](04-enums.md) · [Labels](../06-reading/06-labels.md) · [State transitions](../03-capture/08-state-transitions.md) · [The write path](../01-concepts/03-the-write-path.md)

### Mass operations

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `mass_operations.mode` | string | `summary` (**code**) | What a query that asked for it with `->auditing()` writes down: `summary` (one entry for the whole operation), `individual` (one per row with its real before), or `hybrid` (summary plus individuals while the set stays under the threshold). `summary` is the only one whose cost does not grow with the size of the set. |
| `mass_operations.threshold` | int | `100` (**code**) | How many rows `hybrid` will describe one by one. Floored at 1. |
| `mass_operations.sample` | int | `20` (**code**) | How many values of a long criteria set are recorded: a `whereIn` over five thousand identifiers records the count and this many of them, never the list. Floored at 1. |

**Explained in:** [Mass operations](../03-capture/05-mass-operations.md)

### Retention, pruning and compliance

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `retention` | map\<string, string\> | `[]` | Policies keyed by **logical type**: `model:App\Models\Patient` (or a bare FQCN) names the subject and is resolved through the morph map; anything else is matched against `audit_type`. Periods are spans — `7 years`, `90 days`, `2 weeks 3 days`, `P1Y` — never relative dates. What no policy names is kept forever. |
| `prune.windows` | int | `100` (**code**) | How many anchored windows one run examines per stream. A window a long policy holds is re-examined on every run, and this is what keeps that from growing without bound. Floored at 1. |
| `prune.batch` | int | `1000` (**code**) | The **sequence span** one DELETE statement covers — not a row count. Named by sequence rather than a `LIMIT` so the three engines compile one plan and an interrupted run resumes by arithmetic. Floored at 1. |
| `prune.pause` | int microseconds | `0` (**code**) | How long the prune waits between two statements, so it does not compete with the writes it is making room for. Floored at 0, and applied after every batch including the last of a window. **Microseconds, not milliseconds.** |
| `compliance` | bool | `false` | Makes signatures and anchors mandatory, forces `on_write_failure` to `throw`, requires an actor on every redaction, refuses `sentinel:prune --action=delete` for a range with no archive batch, and records every read through the Query API. Refused **at boot** if `integrity.signature.enabled` or `integrity.checkpoints.enabled` is off. |

**Explained in:** [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md)

### Trace context

Sentinel reads and forwards W3C Trace Context on its own and never requires an OpenTelemetry SDK.
When one is registered its active span wins over the incoming header. Every key here carries its
default in code.

| Key | Type | Default | What it does · when you change it |
|---|---|---|---|
| `telemetry.enabled` | bool | `false` | Master switch. Off: no header is read, no span provider is asked, no envelope is written, and `Sentinel::trace()` returns null. It also stops the open business transaction from crossing the queue, which rides in the same envelope. |
| `telemetry.service_name` | ?string | `env('APP_NAME', 'laravel')`, then `config('app.name')` | What this service calls itself inside a trace. It lands inside the entry's `context` JSON — there is no column for it. |
| `telemetry.trust_incoming_header` | bool | `true` | Whether an inbound `traceparent` is believed. Turn it **off** at any public edge: the header is a value the caller chooses and `trace_id` is indexed, so a third party can otherwise file your entries under a trace of their own. |
| `telemetry.propagate_context` | bool | `true` | Whether the envelope is sealed into the payload of jobs this process queues. |
| `telemetry.store_tracestate` | bool | `false` | Whether an inbound `tracestate` is stored in the entry's `context`. Leave it off unless you consume the vendor list — `context` is inside the hashed canonical payload, so this puts client-controlled text there. |
| `telemetry.root_context` | bool | `false` | Whether a run nobody traced opens a trace of its own, so every entry of a command or a scheduled task shares one `trace_id`. |

**Explained in:** [Distributed tracing](../04-context/06-distributed-tracing.md)

---

## Environment variables

Every variable the shipped file reads, and the key it feeds.

| Variable | Key | Shipped fallback |
|---|---|---|
| `SENTINEL_ENABLED` | `enabled` | `true` |
| `SENTINEL_MODE` | `mode` | `sync` |
| `SENTINEL_QUEUE_CONNECTION` | `queue.connection` | `null` |
| `SENTINEL_QUEUE` | `queue.queue` | `null` |
| `SENTINEL_BUFFER_STORE` | `buffer.store` | `redis` |
| `SENTINEL_BUFFER_CONNECTION` | `buffer.connection` | `null` |
| `SENTINEL_ON_WRITE_FAILURE` | `on_write_failure` | `throw` |
| `SENTINEL_LOG_CHANNEL` | `log_channel` | `null` |
| `SENTINEL_LEDGER` | `ledger.default` | `database` |
| `SENTINEL_ARCHIVE_DISK` | `ledger.ledgers.archive.disk` | `local` |
| `SENTINEL_DB_CONNECTION` | `database.connection` | `null` |
| `SENTINEL_ENCRYPTION_KEY` | `security.encryption.keys.default` | `null` → `APP_KEY` |
| `SENTINEL_HASH_SALT` | `security.hashing.salt` | `null` → derived from `APP_KEY` |
| `SENTINEL_SIGNING_KEY` | `integrity.signature.keys.default` | `null` → derived from `APP_KEY` under `hmac` |
| `SENTINEL_SIGNING_PRIVATE_KEY` | `integrity.signature.private_key` | `null` |
| `APP_NAME` | `telemetry.service_name` | `laravel` |

> ⚠️ **Warning.** Laravel converts only the literals `true`, `false`, `null` and `empty` from `.env`.
> `SENTINEL_ENABLED=0` therefore arrives as the **string** `"0"`, and `Config::enabled()` refuses it
> with `sentinel.enabled must be a boolean, string given`. Write `false`.

---

## Three rules that apply to every key

### Most defaults live in code as well as in the file

`ServiceProvider::mergeConfigFrom()` merges one level deep. An application that published
`config/sentinel.php` before a nested key existed would otherwise win with a subtree that lacks it —
and an application that published `resolvers` while it was an empty array would end up with no
resolvers at all. So `Support\Config` re-declares the default for almost every nested key:
`queue.*`, `buffer.*`, `models.*`, `resolvers.*`, `pipeline`, `mass_operations.*`, `prune.*`,
`telemetry.*`, `security.*`, `integrity.checkpoints.*`, `integrity.signature.*`,
`ledger.ledgers.archive.*` and `ledger.ledgers.fanout.*`.

The exceptions matter more than the rule, because they are the keys a partial subtree breaks. These
are read as **required** and raise `ConfigurationException::missing` naming the exact dotted path:

| Key | Read by |
|---|---|
| `enabled`, `mode`, `on_write_failure`, `compliance`, `retention` | top-level — supplied by the merge unless the published file declares them as `null` |
| `tables.prefix`, `tables.<name>` | `Config::table()` |
| `ledger.default` | the container's `Ledger` binding |
| `snapshots.enabled`, `snapshots.include_hidden` | `Snapshot\SnapshotBuilder` |
| `tags.enabled` | the `ResolveTags` stage |
| `severity.default`, `severity.events` | `Config::defaultSeverity()` |
| `transitions.attribute` | `Transitions\TransitionBuilder` |
| `transactions.after_commit` | `Dispatch\Dispatcher` |
| `integrity.algorithm`, `integrity.stream` | `Ledger\EntryBuilder`, `Integrity\Stream` |

```php
// A published file that declares a subtree must declare every required key inside it.
'integrity' => [
    'algorithm' => 'sha256',
    'stream' => 'global',
],  // ✅ checkpoints and signature fall back to the code defaults

'snapshots' => [
    'enabled' => true,
],  // ❌ throws: [sentinel.snapshots.include_hidden] is not set
```

### An empty driver subtree takes no options

`ledger.ledgers.database`, `ledger.ledgers.memory` and `ledger.ledgers.null` ship as `[]` and stay
that way. Nothing in the package reads a key under them. A `'connection' => 'audits'` written into
`ledger.ledgers.database` is ignored without a word — the audit connection is `database.connection`,
one level up. The two subtrees that *do* take options are `archive` and `fanout`.

### Values are validated at read time, not degraded

Every accessor validates and throws rather than falling back to something plausible. The exceptions
are developer-facing plain English, not translated strings, and each one names the key.

| Failure | Exception | Message shape |
|---|---|---|
| A required key is absent | `ConfigurationException::missing` | `Sentinel configuration key [sentinel.<key>] is not set.` |
| Wrong type | `ConfigurationException::expected` | `[sentinel.<key>] must be a boolean, string given.` |
| A value outside the accepted set | `ConfigurationException::unknown` | `[sentinel.<key>] has unknown value [x]. Accepted: a, b, c.` |
| A class that does not exist, or is not what the key promises | `ConfigurationException::invalidClass` | `[sentinel.<key>] must be <FQCN> or a subclass of it, [x] given.` |
| No hashing salt or signing key, and no `APP_KEY` to derive one from | `ConfigurationException::missingApplicationKey` | names the key and tells you to run `key:generate` |
| `ledger.default` set to `archive` | `ConfigurationException::coldLedgerAsDefault` | explains that the archive keeps its stream tail on the instance |
| The pre-1.0 `archive.compress` key still present | `ConfigurationException::renamedArchiveCodec` | tells you to write `codec => 'gzip'` or `codec => null` |
| A retention period that is a relative date, or one that does not reach into the past | `ConfigurationException::unreadableRetention` / `::instantRetention` | names the key and the declared period |
| Two retention keys governing the same entries | `ConfigurationException::ambiguousRetention` | names both keys and the target |
| A resolved stream name that is empty or over 64 characters | `ConfigurationException::streamEmpty` / `::streamTooLong` | the name is part of the hash prefix, so it is never truncated |
| An encryption `key_id` the ring does not hold (and it is not `default`) | `EncryptionException::unknownKey` | names the identifier and the config key to declare it under |
| `compliance = true` without signatures or anchors | `ComplianceException::incomplete` | names every switch that is missing — **thrown at boot** |

> 📌 **Note.** Only `compliance` fails at boot. Everything else fails where it is read, which for a
> capture-path key means on the first write — and under a deferred (after-commit) write, that failure
> is announced and logged rather than thrown. See [Exceptions](06-exceptions.md).

---

## Three configurations

Each of these is a published `config/sentinel.php` that names only what it changes. Every top-level
key it omits comes from the package's own file.

### Minimal — one chain, synchronous, nothing optional

```php
<?php

declare(strict_types=1);

return [
    'enabled' => env('SENTINEL_ENABLED', true),

    'mode' => 'sync',

    'integrity' => [
        'algorithm' => 'sha256',
        'stream' => 'global',
    ],
];
```

`stream` is spelled out because the shipped default is `tenant`, which behaves exactly like `global`
until a tenant actually resolves — and the moment one does, entries move to a `tenant:<id>` chain of
their own with `sequence` restarting at 1. Say which you want before you wire tenancy.

### Tenant SaaS — queued writes, per-tenant chains, retention

```php
<?php

declare(strict_types=1);

use App\Sentinel\WorkspaceTenantResolver;

return [
    'mode' => 'queue',

    'queue' => [
        'connection' => 'audits',
        'queue' => 'trail',
    ],

    'resolvers' => [
        'tenant' => ['class' => WorkspaceTenantResolver::class],
    ],

    'integrity' => [
        'algorithm' => 'sha256',
        'stream' => 'tenant',
        'checkpoints' => ['enabled' => false, 'every' => 1000],
    ],

    'security' => [
        'redaction' => ['fields' => ['ip', 'user_agent']],
    ],

    'tags' => [
        'enabled' => true,
        'default' => ['saas'],
    ],

    'retention' => [
        'model:App\Models\Invoice' => '7 years',
        'auth' => '90 days',
    ],

    'prune' => ['windows' => 100, 'batch' => 500, 'pause' => 2000],

    'telemetry' => [
        'enabled' => true,
        'trust_incoming_header' => false,
        'root_context' => true,
    ],
];
```

A resolver **class** rather than a `resolvers.tenant.using` closure, so `config:cache` still works.
Anchors are emitted by `sentinel:checkpoint` on a schedule rather than on the write path, which is
what `checkpoints.enabled => false` means here — retention needs anchors, and nothing is ever
released from a stream that has none.

### Compliance — signed, anchored, archived before deleting

```php
<?php

declare(strict_types=1);

return [
    'mode' => 'sync',

    'on_write_failure' => 'throw',

    'compliance' => true,

    'integrity' => [
        'algorithm' => 'sha256',
        'stream' => 'tenant',
        'checkpoints' => ['enabled' => true, 'every' => 1000],
        'signature' => [
            'enabled' => true,
            'signer' => 'openssl',
            'algorithm' => 'sha256',
            'key_id' => 'v2',
            'keys' => [
                'v1' => env('SENTINEL_SIGNING_PUBLIC_V1'),  // retired: verifies, never signs
                'v2' => env('SENTINEL_SIGNING_PUBLIC_V2'),
            ],
            'private_key' => env('SENTINEL_SIGNING_PRIVATE_V2'),  // keep this off verifying nodes
        ],
    ],

    'ledger' => [
        'default' => 'database',
        'ledgers' => [
            'archive' => [
                'disk' => env('SENTINEL_ARCHIVE_DISK', 's3'),
                'path' => 'sentinel',
                'codec' => 'gzip',
                'batch' => 1000,
            ],
        ],
    ],

    'security' => [
        'encryption' => [
            'cipher' => 'aes-256-gcm',
            'key_id' => '2027-q1',
            'keys' => [
                'default' => env('SENTINEL_ENCRYPTION_KEY'),
                '2027-q1' => env('SENTINEL_ENCRYPTION_KEY_2027_Q1'),
            ],
            'fields' => ['national_id'],
        ],
        'hashing' => ['algorithm' => 'sha256', 'salt' => env('SENTINEL_HASH_SALT'), 'fields' => []],
        'redaction' => ['mask' => '*', 'fields' => [], 'masker' => null, 'maskers' => []],
    ],

    'retention' => [
        'model:App\Models\Patient' => '10 years',
        'access' => '2 years',
    ],
];
```

`compliance => true` with either signature switch off means the application does not boot — that
refusal is the feature, because the first write that should have been signed may be a year away. The
`security` subtree is spelled out in full here because it is published as one block: `redaction` and
`hashing` would otherwise vanish with it.

> 🧪 **Verify it.** `php artisan about` prints six rows for Sentinel — version, mode, ledger, payload
> version, compliance mode and telemetry — and deliberately prints no key, key identifier or signer
> configuration, because `about` output is pasted into issues and captured by deploy logs.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `[sentinel.snapshots.include_hidden] is not set` on the first write after publishing a config | A published subtree replaced the package's own wholesale, and one of the required keys inside it was missing. The merge is one level deep. | Declare every required key inside any subtree you publish, or drop the subtree and let the package's own supply it. |
| `[sentinel.enabled] must be a boolean, string given` | `.env` holds `SENTINEL_ENABLED=0` or `=1`. Laravel only converts `true`/`false`/`null`/`empty`. | Write `SENTINEL_ENABLED=false`. |
| A stage you added to the pipeline never runs after a package upgrade — or a new shipped stage never runs | A non-empty `pipeline` list is taken verbatim. The published file pins the list it was published with. | Re-read `pipeline` against `UPGRADE.md` on every upgrade and declare the full list you want. |
| An option written under `ledger.ledgers.database` has no effect and raises nothing | That subtree ships empty and is never read. | Use `database.connection` for the audit connection; only `archive` and `fanout` take options. |
| Entries suddenly land on a `tenant:<id>` chain with `sequence` restarting at 1 | `integrity.stream` ships as `tenant`, which behaves like `global` until a tenant first resolves. | Set `integrity.stream` explicitly before wiring a tenant resolver. Old chains keep verifying; they simply stop growing. |
| `php artisan config:cache` fails, or a stream strategy stops being honoured | A `Closure` in `integrity.stream`, `resolvers.tenant.using` or `resolvers.request.api` cannot be serialised into a cached config. | Use the class-string form: `Contracts\StreamResolver` for the stream, `Contracts\Resolver` for the tenant. |
| A prune that should take seconds runs for hours | `prune.pause` was tuned as if it were milliseconds. It is **microseconds**, applied after every batch. | Divide by 1000. `2000` is 2 ms. |
| `sentinel:prune` frees nothing although the policy has long expired | The unit is the anchored window, whole: the effective retention of a range is that of its longest-lived entry, and a stream with no anchors releases nothing at all. | Run `sentinel:checkpoint` first, and read the Note column of `sentinel:prune --dry-run`. |
| The application refuses to boot with `ComplianceException` | `compliance => true` with `integrity.signature.enabled` or `integrity.checkpoints.enabled` off. | Turn both on, or turn compliance off. The refusal names each missing switch. |
| An exported manifest carries `"signature": ""` and `"signature_key_id": "null"` | `integrity.signature.enabled` is off, so the current signer is the null signer. | Turn signing on before relying on any export manifest — compliance mode forces it. |
| Entries stop being readable after a key rotation | An identifier was removed from `security.encryption.keys`. Entries record the identifier they were written with. | Never remove a key whose entries you still need. Add the new one and move `key_id`. |
| `sentinel:flush` exits 2 and the buffer never empties | `mode` was changed away from `buffered` with entries still waiting. Both shutdown hooks and the command refuse to touch the buffer under another mode. | Set `mode` back to `buffered`, flush until it prints `Settled 0 entries`, then switch. |

---

## ✅ Best practices

✅ **Do** — publish the file with `sentinel:install` and edit only what you mean to change. Every top-level
key you leave out comes from the package, which is what makes an upgrade quiet.

```php
// config/sentinel.php — the whole file
return [
    'mode' => 'queue',
    'retention' => ['auth' => '90 days'],
];
```

❌ **Don't** — publish a subtree with only the key you wanted to change in it. The merge is one level
deep, so the rest of that subtree is gone, and the first required key inside it throws on the next write.

```php
'snapshots' => ['include_hidden' => false],
// ConfigurationException: [sentinel.snapshots.enabled] is not set.
```

✅ **Do** — name a resolver or stream **class** when you cache configuration. A class-string survives
`config:cache`; a closure does not.

```php
'integrity' => ['algorithm' => 'sha256', 'stream' => App\Sentinel\RegionStream::class],
'resolvers' => ['tenant' => ['class' => App\Sentinel\WorkspaceTenantResolver::class]],
```

❌ **Don't** — reach for a closure in `integrity.stream` on an installation that runs `config:cache`.
The stream name is part of the hash prefix; losing the strategy is not a cosmetic failure.

```php
'integrity' => ['stream' => fn (AuditData $audit): string => 'region:'.$audit->context['region']],
```

✅ **Do** — decide `integrity.stream` before there is data, and treat it as frozen afterwards. The
resolved name goes into the hash prefix, so changing it forks the history into two independent chains.

```php
'integrity' => ['algorithm' => 'sha256', 'stream' => 'global'],  // single-tenant, one chain, forever
```

❌ **Don't** — change `integrity.stream` or `tables.prefix` on an installation that already holds
entries and expect the history to follow. Nothing is rewritten: old rows keep their own stream in
their own hash prefix, and old tables keep their own name.

```php
'tables' => ['prefix' => 'audit_', /* … */],  // the seven tables the migrations created are still sentinel_*
```

✅ **Do** — rotate a key by moving the identifier and leaving the old one on the ring. Every entry
records what wrote it, so yesterday's entries keep working with yesterday's key.

```php
'integrity' => ['signature' => [
    'key_id' => 'v2',
    'keys' => ['v1' => env('SENTINEL_SIGNING_PUBLIC_V1'), 'v2' => env('SENTINEL_SIGNING_PUBLIC_V2')],
]],
```

❌ **Don't** — remove a retired key to tidy the ring. Every entry it signed becomes `unknown_key` and
stops being provable; every value it encrypted stops being readable. Both are permanent.

```php
'integrity' => ['signature' => ['key_id' => 'v2', 'keys' => ['v2' => env('SENTINEL_SIGNING_PUBLIC_V2')]]],
```

✅ **Do** — name cross-cutting protected fields in `security.*.fields`. Those lists are a union with
what models declare and are the only lever that reaches an entry with no model subject at all.

```php
'security' => ['redaction' => ['mask' => '*', 'fields' => ['ip'], 'masker' => null, 'maskers' => []]],
```

❌ **Don't** — expect a config list to cancel a model declaration. There is no override direction:
`security.redaction.fields` adds to `$auditRedact` and can never subtract from it.

```php
'security' => ['redaction' => ['fields' => []]],  // does not un-redact a field the model declared
```

✅ **Do** — read `prune.batch` as a sequence span and `prune.pause` as microseconds before you tune
either. A half-empty range issues the same number of statements as a dense one.

```php
'prune' => ['windows' => 100, 'batch' => 500, 'pause' => 2000],  // 2 ms between statements
```

❌ **Don't** — set `buffer.size` or `buffer.flush_interval` to `0` expecting "never flush". Both floor
at 1, so `0` gives you a flush on every single entry — the slowest configuration of the fastest mode.

```php
'buffer' => ['store' => 'redis', 'size' => 0, 'flush_interval' => 0],  // becomes 1 and 1
```

---

**See also:** [Installation](../02-getting-started/01-installation.md) · [Choosing your setup](../02-getting-started/05-choosing-your-setup.md) · [Exceptions](06-exceptions.md) · [Schema](03-schema.md) · [API stability](09-api-stability.md) · [Production readiness](../13-best-practices/04-production-readiness.md)
