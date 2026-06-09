<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'throttle:bulk-send'])->group(function (): void {
    Route::post('/notifications/bulk', [NotificationController::class, 'bulkSend']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/notifications/batch/{batchId}', [NotificationController::class, 'batchStatus']);
    Route::get('/notifications/subscriber/{recipientId}', [NotificationController::class, 'subscriberHistory']);
});
