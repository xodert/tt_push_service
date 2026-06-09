<?php

declare(strict_types=1);

namespace App\Services\Gateway;

interface GatewayInterface
{
    public function send(string $recipient, string $message): GatewayResult;
}
