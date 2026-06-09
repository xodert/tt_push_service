<?php

declare(strict_types=1);

use App\Jobs\ProcessNotificationJob;
use App\Kafka\NotificationMessageHandler;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationStatus;
use App\Services\Gateway\GatewayInterface;
use App\Services\Gateway\GatewayResult;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\ConsumedMessage;

beforeEach(function (): void {
    Kafka::fake();
});

function kafkaMessage(mixed $body, string $topic = 'notifications_high'): ConsumerMessage
{
    return new ConsumedMessage(
        topicName: $topic,
        partition: 0,
        headers: [],
        body: $body,
        key: null,
        offset: 1,
        timestamp: null,
    );
}

function seedQueuedNotification(string $type = 'high'): Notification
{
    $user  = User::factory()->create(['can_send_transactional' => true]);
    $batch = NotificationBatch::query()->create([
        'batch_id'        => Str::uuid(),
        'user_id'         => $user->id,
        'idempotency_key' => Str::uuid(),
        'channel'         => 'sms',
        'type'            => $type,
        'message'         => 'Consumer test message',
        'total_count'     => 1,
    ]);

    return Notification::query()->create([
        'notification_id' => Str::uuid()->toString(),
        'batch_id'        => $batch->id,
        'recipient_id'    => '+79001234567',
        'channel'         => 'sms',
        'type'            => $type,
        'message'         => 'Consumer test message',
        'status'          => NotificationStatus::Queued,
        'attempts'        => 0,
    ]);
}

it('dispatches the job to the high queue and commits the offset for a high topic message', function (): void {
    Queue::fake();

    $notification = seedQueuedNotification();
    $consumer     = Mockery::spy(MessageConsumer::class);
    $message      = kafkaMessage(['notification_id' => $notification->notification_id], 'notifications_high');

    app(NotificationMessageHandler::class)($message, $consumer);

    Queue::assertPushedOn(
        'high',
        ProcessNotificationJob::class,
        fn (ProcessNotificationJob $job): bool => $job->notificationId === $notification->notification_id
    );
    $consumer->shouldHaveReceived('commit')->once();
});

it('dispatches the job to the low queue for a low topic message', function (): void {
    Queue::fake();

    $notification = seedQueuedNotification('low');
    $consumer     = Mockery::spy(MessageConsumer::class);
    $message      = kafkaMessage(['notification_id' => $notification->notification_id], 'notifications_low');

    app(NotificationMessageHandler::class)($message, $consumer);

    Queue::assertPushedOn('low', ProcessNotificationJob::class);
    $consumer->shouldHaveReceived('commit')->once();
});

it('routes malformed messages to the DLQ, commits the offset and dispatches nothing', function (): void {
    Queue::fake();
    Kafka::fake();

    $consumer = Mockery::spy(MessageConsumer::class);
    $message  = kafkaMessage(['unexpected' => 'payload']);

    app(NotificationMessageHandler::class)($message, $consumer);

    Kafka::assertPublishedOn('notifications.dlq');
    Queue::assertNothingPushed();
    $consumer->shouldHaveReceived('commit')->once();
});

it('does not commit the offset when job dispatch fails', function (): void {
    $notification = seedQueuedNotification();

    $this->mock(BusDispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('Queue backend unavailable'));

    $consumer = Mockery::spy(MessageConsumer::class);
    $message  = kafkaMessage(['notification_id' => $notification->notification_id]);

    expect(fn () => app(NotificationMessageHandler::class)($message, $consumer))
        ->toThrow(RuntimeException::class, 'Queue backend unavailable');

    $consumer->shouldNotHaveReceived('commit');
});

it('processes a kafka message end-to-end: gateway is called and status becomes delivered', function (): void {
    // QUEUE_CONNECTION=sync in tests: dispatch() runs the job inline,
    // covering the full chain Kafka message -> job -> gateway -> DB status.
    $sentTo = [];
    app()->instance('gateway.sms', new class($sentTo) implements GatewayInterface
    {
        public function __construct(private array &$sentTo) {}

        public function send(string $recipient, string $message): GatewayResult
        {
            $this->sentTo[] = $recipient;

            return GatewayResult::ok();
        }
    });

    $notification = seedQueuedNotification();
    $consumer     = Mockery::spy(MessageConsumer::class);
    $message      = kafkaMessage(['notification_id' => $notification->notification_id], 'notifications_high');

    app(NotificationMessageHandler::class)($message, $consumer);

    $notification->refresh();
    expect($sentTo)->toBe(['+79001234567'])
        ->and($notification->status)->toBe(NotificationStatus::Delivered)
        ->and($notification->delivered_at)->not->toBeNull();

    $consumer->shouldHaveReceived('commit')->once();
});
