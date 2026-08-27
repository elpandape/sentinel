<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class SecretiveSubject extends Model
{
    use Auditable;

    public const string EXCLUDED = 'plaintext-excluded-9f2a';

    public const string REDACTED = 'plaintext-redacted-4c7b';

    public const string ENCRYPTED = 'plaintext-encrypted-8e1d';

    public const string HASHED = 'plaintext-hashed-2b6f';

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['status'];

    /**
     * @var list<string>
     */
    protected array $auditRedact = ['email'];

    /**
     * @var list<string>
     */
    protected array $auditEncrypt = ['secret'];

    /**
     * @var list<string>
     */
    protected array $auditHash = ['price'];

    public function getTable(): string
    {
        return 'fixture_audited_subjects';
    }

    public function usesTimestamps(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return [];
    }
}
