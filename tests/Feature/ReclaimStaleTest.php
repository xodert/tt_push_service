<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;

beforeEach(function (): void {
    Kafka::fake();
});

/** @param array<string, mixed> $notificationAttrs */
function seedNotificationForReclaim(array $notificationAttrs = []): Notification
{
    $user  = User::factory()->create(['can_send_transactional' => true]);
    $batch = NotificationBatch::query()->create([
        'batch_id'        => Str::uuid(),
        'user_id'         => $user->id,
        'idempotency_key' => Str::uuid(),
        'channel'         => 'sms',
        'type'            => 'high',
        'message'         => 'Reclaim test',
        'total_count'     => 1,
    ]);

    $notification = Notification::query()->create(array_merge([
        'notification_id' => Str::uuid()->toString(),
        'batch_id'        => $batch->id,
        'recipient_id'    => '+79001234567',
        'channel'         => 'sms',
        'type'            => 'high',
        'message'         => 'Reclaim test',
        'status'          => NotificationStatus::Queued,
        'attempts'        => 0,
    ], $notificationAttrs));

    // Bypass Eloquent so updated_at is not bumped automatically.
    DB::table('notifications')->where('id', $notification->id)->update(array_intersect_key(
        $notificationAttrs,
        array_flip(['sent_at', 'updated_at'])
    ) ?: ['updated_at' => $notification->updated_at]);

    return $notification->refresh();
}

it('requeues a stale sent notification and republishes it to kafka', function (): void {
    $notification = seedNotificationForReclaim([
        'status'     => NotificationStatus::Sent,
        'attempts'   => 1,
        'sent_at'    => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('notifications:reclaim-stale')->assertSuccessful();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Queued);

    Kafka::assertPublishedOn('notifications_high');
});

it('rejects a stale sent notification when attempts are exhausted', function (): void {
    $notification = seedNotificationForReclaim([
        'status'     => NotificationStatus::Sent,
        'attempts'   => 3,
        'sent_at'    => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('notifications:reclaim-stale')->assertSuccessful();

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Rejected)
        ->and($notification->error_message)->toContain('Reclaimed');

    Kafka::assertNothingPublished();
});

it('does not touch sent notifications within the lease window', function (): void {
    $notification = seedNotificationForReclaim([
        'status'   => NotificationStatus::Sent,
        'attempts' => 1,
        'sent_at'  => now()->subMinute(),
    ]);

    $this->artisan('notifications:reclaim-stale')->assertSuccessful();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
    Kafka::assertNothingPublished();
});

it('republishes stale queued notifications that never reached kafka', function (): void {
    $notification = seedNotificationForReclaim([
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('notifications:reclaim-stale')->assertSuccessful();

    Kafka::assertPublishedOn('notifications_high');

    // updated_at is bumped so the next scheduler run does not republish again.
    expect($notification->refresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('does not republish queued notifications within the lease window', function (): void {
    seedNotificationForReclaim();

    $this->artisan('notifications:reclaim-stale')->assertSuccessful();

    Kafka::assertNothingPublished();
});
