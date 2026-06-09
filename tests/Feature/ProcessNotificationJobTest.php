<?php

declare(strict_types=1);

use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationStatus;
use App\Services\Gateway\GatewayInterface;
use App\Services\Gateway\GatewayResult;
use Illuminate\Support\Str;

function makeNotification(array $overrides = []): Notification
{
    $user  = User::factory()->create(['can_send_transactional' => true]);
    $batch = NotificationBatch::query()->create(array_merge([
        'batch_id'        => Str::uuid(),
        'user_id'         => $user->id,
        'idempotency_key' => Str::uuid(),
        'channel'         => 'sms',
        'type'            => 'high',
        'message'         => 'Test message',
        'total_count'     => 1,
    ], $overrides['batch'] ?? []));

    return Notification::query()->create(array_merge([
        'notification_id' => Str::uuid()->toString(),
        'batch_id'        => $batch->id,
        'recipient_id'    => '+79001234567',
        'channel'         => 'sms',
        'type'            => 'high',
        'message'         => 'Test message',
        'status'          => NotificationStatus::Queued,
        'attempts'        => 0,
    ], $overrides['notification'] ?? []));
}

function fakeGateway(GatewayResult $result, string $binding = 'gateway.sms'): void
{
    app()->instance($binding, new readonly class($result) implements GatewayInterface
    {
        public function __construct(private GatewayResult $result) {}

        public function send(string $recipient, string $message): GatewayResult
        {
            return $this->result;
        }
    });
}

it('marks notification as delivered on gateway success', function (): void {
    fakeGateway(GatewayResult::ok());

    $notification = makeNotification();
    (new ProcessNotificationJob($notification->notification_id))->handle();

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Delivered)
        ->and($notification->delivered_at)->not->toBeNull()
        ->and($notification->sent_at)->not->toBeNull();
});

it('marks notification as rejected on permanent gateway failure', function (): void {
    fakeGateway(GatewayResult::permanentFailure('Invalid phone number format'));

    $notification = makeNotification();
    (new ProcessNotificationJob($notification->notification_id))->handle();

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Rejected)
        ->and($notification->error_message)->toBe('Invalid phone number format');
});

it('increments attempts counter on each process', function (): void {
    fakeGateway(GatewayResult::ok());

    $notification = makeNotification();
    expect($notification->attempts)->toBe(0);

    (new ProcessNotificationJob($notification->notification_id))->handle();

    expect($notification->refresh()->attempts)->toBe(1);
});

it('skips already processed notifications (idempotent job)', function (): void {
    $callCount = 0;
    app()->instance('gateway.sms', new class($callCount) implements GatewayInterface
    {
        public function __construct(private int &$callCount) {}

        public function send(string $recipient, string $message): GatewayResult
        {
            $this->callCount++;

            return GatewayResult::ok();
        }
    });

    $notification = makeNotification([
        'notification' => ['status' => NotificationStatus::Delivered],
    ]);

    (new ProcessNotificationJob($notification->notification_id))->handle();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Delivered)
        ->and($callCount)->toBe(0);
});

it('marks notification as rejected when all retries exhausted', function (): void {
    $notification = makeNotification();

    $job = new ProcessNotificationJob($notification->notification_id);
    $job->failed(new RuntimeException('Max retries reached'));

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Rejected)
        ->and($notification->error_message)->toBe('Max retries reached');
});

it('resets status to queued and throws on temporary gateway failure', function (): void {
    app()->instance('gateway.sms', new readonly class implements GatewayInterface
    {
        public function send(string $recipient, string $message): GatewayResult
        {
            return GatewayResult::temporaryFailure('Gateway timeout');
        }
    });

    $notification = makeNotification();

    expect(fn () => (new ProcessNotificationJob($notification->notification_id))->handle())
        ->toThrow(RuntimeException::class, 'Gateway timeout');

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Queued);
});

it('prevents parallel double-processing via atomic status claim', function (): void {
    fakeGateway(GatewayResult::ok());

    $notification = makeNotification();

    // Simulate two workers racing: both call handle() on the same notification.
    (new ProcessNotificationJob($notification->notification_id))->handle(); // first worker wins
    (new ProcessNotificationJob($notification->notification_id))->handle(); // second worker skips

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Delivered)
        ->and($notification->attempts)->toBe(1); // incremented only once
});

it('uses email gateway for email channel notifications', function (): void {
    fakeGateway(GatewayResult::ok(), 'gateway.email');

    $user  = User::factory()->create();
    $batch = NotificationBatch::query()->create([
        'batch_id'        => Str::uuid(),
        'user_id'         => $user->id,
        'idempotency_key' => Str::uuid(),
        'channel'         => 'email',
        'type'            => 'low',
        'message'         => 'Test message',
        'total_count'     => 1,
    ]);

    $notification = Notification::query()->create([
        'notification_id' => Str::uuid()->toString(),
        'batch_id'        => $batch->id,
        'recipient_id'    => 'user@example.com',
        'channel'         => 'email',
        'type'            => 'low',
        'message'         => 'Test message',
        'status'          => NotificationStatus::Queued,
        'attempts'        => 0,
    ]);

    (new ProcessNotificationJob($notification->notification_id))->handle();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Delivered);
});
