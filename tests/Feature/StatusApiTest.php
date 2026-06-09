<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationStatus;
use Illuminate\Support\Str;

function createBatchWithNotifications(User $user, array $statuses): NotificationBatch
{
    $batch = NotificationBatch::query()->create([
        'batch_id'        => Str::uuid(),
        'user_id'         => $user->id,
        'idempotency_key' => Str::uuid(),
        'channel'         => 'sms',
        'type'            => 'high',
        'message'         => 'Test',
        'total_count'     => count($statuses),
    ]);

    foreach ($statuses as $i => $status) {
        Notification::query()->create([
            'notification_id' => Str::uuid(),
            'batch_id'        => $batch->id,
            'recipient_id'    => '+7900000'.$i,
            'channel'         => 'sms',
            'type'            => 'high',
            'message'         => 'Test',
            'status'          => $status,
        ]);
    }

    return $batch;
}

it('returns 401 for unauthenticated batch status request', function (): void {
    $this->getJson('/api/notifications/batch/'.Str::uuid())
        ->assertUnauthorized();
});

it('returns batch status with correct counters', function (): void {
    $user  = User::factory()->create(['can_send_transactional' => true]);
    $batch = createBatchWithNotifications($user, [
        NotificationStatus::Delivered,
        NotificationStatus::Delivered,
        NotificationStatus::Rejected,
        NotificationStatus::Queued,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/notifications/batch/'.$batch->batch_id)
        ->assertOk()
        ->assertJson([
            'batch_id'        => $batch->batch_id,
            'total_count'     => 4,
            'delivered_count' => 2,
            'rejected_count'  => 1,
            'queued_count'    => 1,
        ]);
});

it('returns 404 for non-existent batch', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/notifications/batch/non-existent-uuid')
        ->assertNotFound();
});

it('returns subscriber notification history paginated', function (): void {
    $user  = User::factory()->create(['can_send_transactional' => true]);
    $batch = createBatchWithNotifications($user, [
        NotificationStatus::Delivered,
        NotificationStatus::Rejected,
    ]);

    $recipientId = urlencode('+79000000');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/notifications/subscriber/'.$recipientId)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['notification_id', 'channel', 'status', 'created_at'],
            ],
            'total',
            'per_page',
        ]);
});

it('returns empty data for subscriber with no notifications', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/notifications/subscriber/unknown-recipient')
        ->assertOk()
        ->assertJson(['data' => []]);
});

it('requires authentication for batch status endpoint', function (): void {
    $this->getJson('/api/notifications/batch/some-uuid')->assertUnauthorized();
});

it('requires authentication for subscriber history endpoint', function (): void {
    $this->getJson('/api/notifications/subscriber/+79001234567')->assertUnauthorized();
});
