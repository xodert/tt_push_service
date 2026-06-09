<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationStatus;
use Illuminate\Support\Facades\Cache;
use Junges\Kafka\Facades\Kafka;

beforeEach(function (): void {
    Kafka::fake();
});

it('requires authentication', function (): void {
    $this->postJson('/api/notifications/bulk', [
        'type'          => 'transactional',
        'channel'       => 'sms',
        'message'       => 'Hello',
        'recipient_ids' => ['+79001234567'],
    ])->assertUnauthorized();
});

it('requires idempotency key header', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'transactional',
            'channel'       => 'sms',
            'message'       => 'Hello',
            'recipient_ids' => ['+79001234567'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idempotency_key']);
});

it('validates required fields', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [], ['Idempotency-Key' => 'test-key'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'channel', 'message', 'recipient_ids']);
});

it('rejects unknown notification type', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'urgent',
            'channel'       => 'sms',
            'message'       => 'Hello',
            'recipient_ids' => ['+79001234567'],
        ], ['Idempotency-Key' => 'bad-type-key'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

it('forbids transactional notifications for marketing-only user', function (): void {
    $user = User::factory()->create(['can_send_transactional' => false]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'transactional',
            'channel'       => 'sms',
            'message'       => 'Your OTP is 1234',
            'recipient_ids' => ['+79001234567'],
        ], ['Idempotency-Key' => 'forbidden-key'])
        ->assertForbidden();

    expect(NotificationBatch::query()->count())->toBe(0);
});

it('allows marketing notifications for any authenticated user', function (): void {
    $user = User::factory()->create(['can_send_transactional' => false]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'marketing',
            'channel'       => 'email',
            'message'       => 'Sale!',
            'recipient_ids' => ['a@example.com'],
        ], ['Idempotency-Key' => 'marketing-ok-key'])
        ->assertStatus(202);
});

it('creates batch and notifications in database for transactional request', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $recipientIds = ['+79001234567', '+79009876543'];

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'transactional',
            'channel'       => 'sms',
            'message'       => 'Your OTP is 1234',
            'recipient_ids' => $recipientIds,
        ], ['Idempotency-Key' => 'unique-key-001'])
        ->assertStatus(202)
        ->assertJsonStructure(['batch_id', 'duplicate']);

    $batchId = $response->json('batch_id');

    $batch = NotificationBatch::query()->where('batch_id', $batchId)->first();
    expect($batch)->not->toBeNull()
        ->and($batch->total_count)->toBe(2)
        ->and($batch->type->value)->toBe('high');

    expect(Notification::query()->where('batch_id', $batch->id)->count())->toBe(2);

    expect(
        Notification::query()->where('batch_id', $batch->id)
            ->where('status', NotificationStatus::Queued)
            ->count()
    )->toBe(2);
});

it('publishes messages to high priority kafka topic for transactional type', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'transactional',
            'channel'       => 'sms',
            'message'       => 'OTP: 9999',
            'recipient_ids' => ['+79001111111', '+79002222222'],
        ], ['Idempotency-Key' => 'high-prio-key'])
        ->assertStatus(202);

    Kafka::assertPublishedOn('notifications_high');
});

it('publishes messages to low priority kafka topic for marketing type', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'marketing',
            'channel'       => 'email',
            'message'       => 'Big sale this weekend!',
            'recipient_ids' => ['customer@example.com'],
        ], ['Idempotency-Key' => 'marketing-key-001'])
        ->assertStatus(202);

    Kafka::assertPublishedOn('notifications_low');
});

it('returns 200 and duplicate flag for duplicate idempotency key', function (): void {
    $user = User::factory()->create();

    $payload = [
        'type'          => 'marketing',
        'channel'       => 'email',
        'message'       => 'Hello!',
        'recipient_ids' => ['a@example.com'],
    ];
    $headers = ['Idempotency-Key' => 'dedup-key-abc'];

    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(202)
        ->json('batch_id');

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(200)
        ->assertJson(['duplicate' => true])
        ->json('batch_id');

    expect($first)->toBe($second);

    // Only one batch should exist
    expect(NotificationBatch::query()->where('idempotency_key', 'dedup-key-abc')->count())->toBe(1);
});

it('scopes idempotency keys per user', function (): void {
    $alice = User::factory()->create();
    $bob   = User::factory()->create();

    $payload = [
        'type'          => 'marketing',
        'channel'       => 'email',
        'message'       => 'Hello!',
        'recipient_ids' => ['a@example.com'],
    ];
    $headers = ['Idempotency-Key' => 'shared-key'];

    $first = $this->actingAs($alice, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(202)
        ->json('batch_id');

    $second = $this->actingAs($bob, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(202)
        ->assertJson(['duplicate' => false])
        ->json('batch_id');

    expect($first)->not->toBe($second)
        ->and(NotificationBatch::query()->where('idempotency_key', 'shared-key')->count())->toBe(2);
});

it('falls back to the database unique constraint when the idempotency cache is lost', function (): void {
    $user = User::factory()->create();

    $payload = [
        'type'          => 'marketing',
        'channel'       => 'email',
        'message'       => 'Hello!',
        'recipient_ids' => ['a@example.com'],
    ];
    $headers = ['Idempotency-Key' => 'redis-lost-key'];

    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(202)
        ->json('batch_id');

    // Simulate Redis flush/restart: the SETNX key is gone, only the DB row remains.
    Cache::flush();

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', $payload, $headers)
        ->assertStatus(200)
        ->assertJson(['duplicate' => true])
        ->json('batch_id');

    expect($first)->toBe($second)
        ->and(NotificationBatch::query()->where('idempotency_key', 'redis-lost-key')->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1);
});

it('does not create duplicate notifications for duplicate request', function (): void {
    $user = User::factory()->create(['can_send_transactional' => true]);

    $headers = ['Idempotency-Key' => 'no-dup-key'];
    $payload = [
        'type'          => 'transactional',
        'channel'       => 'sms',
        'message'       => 'Hello',
        'recipient_ids' => ['+79001234567'],
    ];

    $this->actingAs($user, 'sanctum')->postJson('/api/notifications/bulk', $payload, $headers)->assertStatus(202);
    $this->actingAs($user, 'sanctum')->postJson('/api/notifications/bulk', $payload, $headers)->assertStatus(200);

    expect(Notification::query()->count())->toBe(1);
});

it('creates correct number of notification records for large batch', function (): void {
    $user = User::factory()->create();

    $recipients = array_map(fn ($i) => sprintf('user%d@example.com', $i), range(1, 1000));

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/notifications/bulk', [
            'type'          => 'marketing',
            'channel'       => 'email',
            'message'       => 'Bulk campaign message',
            'recipient_ids' => $recipients,
        ], ['Idempotency-Key' => 'bulk-1000-key'])
        ->assertStatus(202);

    $batch = NotificationBatch::query()->first();
    expect($batch->total_count)->toBe(1000)
        ->and(Notification::query()->count())->toBe(1000);
});
