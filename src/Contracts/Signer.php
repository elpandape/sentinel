<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

interface Signer
{
    public function sign(string $hash): string;

    public function verify(string $hash, string $signature): bool;

    public function keyId(): string;
}
