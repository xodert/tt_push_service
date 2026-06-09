<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Log;

final class MockSmsGateway implements GatewayInterface
{
    public function send(string $recipient, string $message): GatewayResult
    {
        Log::info('MockSmsGateway: sending SMS', ['recipient' => $recipient]);

        // Simulate invalid phone number format
        if (! preg_match('/^\+?[1-9]\d{7,14}$/', $recipient)) {
            return GatewayResult::permanentFailure('Invalid phone number format');
        }

        // Simulate 5% transient failures for retry testing
        if (random_int(1, 100) <= 5) {
            return GatewayResult::temporaryFailure('Gateway temporarily unavailable');
        }

        return GatewayResult::ok();
    }
}
