<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Log;

final class MockEmailGateway implements GatewayInterface
{
    public function send(string $recipient, string $message): GatewayResult
    {
        Log::info('MockEmailGateway: sending email', ['recipient' => $recipient]);

        // Simulate invalid email
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return GatewayResult::permanentFailure('Invalid email address');
        }

        // Simulate 5% transient failures for retry testing
        if (random_int(1, 100) <= 5) {
            return GatewayResult::temporaryFailure('SMTP server temporarily unavailable');
        }

        return GatewayResult::ok();
    }
}
