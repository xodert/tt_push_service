<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class GatewayResult
{
    public function __construct(
        public bool $success,
        public bool $permanent,
        public ?string $error = null,
    ) {}

    public static function ok(): self
    {
        return new self(success: true, permanent: false);
    }

    public static function permanentFailure(string $error): self
    {
        return new self(success: false, permanent: true, error: $error);
    }

    public static function temporaryFailure(string $error): self
    {
        return new self(success: false, permanent: false, error: $error);
    }
}
