<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Context\Identity;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who got in, who did not, and who was shut out. These are entries the way a domain event is an
 * entry: something happened that no model change describes, settled through the same pipeline and
 * the same ledger.
 *
 * Opt-in, and it stays opt-in. The package ships the subscriber and the application registers it;
 * an installation that does not is an installation where nothing about authentication is written,
 * which is the only safe default for a package that would otherwise start recording who logs in
 * the moment it is upgraded.
 *
 * The five events do not share a shape. Login carries a guard, a user and a remember flag; Logout
 * a guard and a user; Failed a guard, maybe a user, and credentials; Lockout only a request; and
 * PasswordReset only a user. Handling them as one would mean inventing the parts that are missing.
 *
 * The credentials of a failed attempt are never read. Failed carries them — marked sensitive by
 * the framework — and the guarantee here is that they are not captured, which is stronger than
 * capturing them and redacting them afterwards.
 */
final readonly class AuthenticationSubscriber
{
    public const string AUDIT_TYPE = 'auth';

    public function __construct(
        private Sentinel $sentinel,
        private Recorder $recorder,
        private Config $config,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            PasswordReset::class => 'passwordReset',
        ];
    }

    public function login(Login $event): void
    {
        $this->record('login', $event->user, $event->guard);
    }

    public function logout(Logout $event): void
    {
        $this->record('logout', $event->user, $event->guard);
    }

    /**
     * The user is null whenever the attempt named nobody the provider could find, which is most of
     * them. An entry with no actor still says an attempt happened, from where, and when — and that
     * is the part worth keeping.
     */
    public function failed(Failed $event): void
    {
        $this->record('failed', $event->user, $event->guard);
    }

    /**
     * No guard and no user: the framework hands a request and nothing else. Whatever identifies
     * the attempt is in the request, and the context engine already records the parts of it that
     * are not credentials.
     */
    public function lockout(): void
    {
        $this->record('lockout', null, null);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->record('password_reset', $event->user, null);
    }

    private function record(string $event, mixed $user, mixed $guard): void
    {
        if (! $this->sentinel->isRecording()) {
            return;
        }

        $actor = $user instanceof Authenticatable ? $this->actor($user) : null;

        $this->recorder->record(
            new AuditData(
                audit_type: self::AUDIT_TYPE,
                event: $event,
                severity: $this->config->defaultSeverity($event),
                occurred_at: CarbonImmutable::now(),
                subject_type: $actor?->type,
                subject_id: $actor?->id,
                metadata: is_string($guard) ? ['guard' => $guard] : null,
            ),
            null,
            $actor,
        );
    }

    /**
     * The person is both the actor and the subject: an authentication event is something someone
     * did, and the thing it happened to is that same someone.
     */
    private function actor(Authenticatable $user): ?Reference
    {
        $id = Identity::id($user);

        return $id === null ? null : new Reference(Identity::type($user), $id);
    }
}
