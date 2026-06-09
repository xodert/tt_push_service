<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Gateway\MockEmailGateway;
use App\Services\Gateway\MockSmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('gateway.sms', MockSmsGateway::class);
        $this->app->singleton('gateway.email', MockEmailGateway::class);
    }

    public function boot(): void
    {
        RateLimiter::for('bulk-send', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by($user?->id);
        });
    }
}
