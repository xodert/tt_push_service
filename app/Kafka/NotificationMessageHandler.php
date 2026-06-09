<?php

declare(strict_types=1);

namespace App\Kafka;

use App\Jobs\ProcessNotificationJob;
use App\NotificationPriority;
use App\Services\KafkaNotificationPublisher;
use Illuminate\Support\Str;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Throwable;

final readonly class NotificationMessageHandler
{
    public function __construct(
        private KafkaNotificationPublisher $publisher,
    ) {}

    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $notificationId = $this->parseNotificationId($message);

        if ($notificationId === null) {
            $this->publisher->publishToDlq($message, 'notification_id missing or invalid');
            $consumer->commit($message);

            return;
        }

        $queue = NotificationPriority::fromKafkaTopic($message->getTopicName() ?? '')->queue();

        dispatch((new ProcessNotificationJob($notificationId))->onQueue($queue));

        // коммит оффсета после dispatch, иначе redelivery
        $consumer->commit($message);
    }

    private function parseNotificationId(ConsumerMessage $message): ?string
    {
        try {
            $raw = $message->getBody();
            /** @var array<string, mixed> $body */
            $body = is_array($raw)
                ? $raw
                : (is_string($raw) ? json_decode($raw, true) : null);

            if (! is_array($body)) {
                return null;
            }

            $value = $body['notification_id'] ?? null;

            if (! is_string($value) || ! Str::isUuid($value)) {
                return null;
            }

            return $value;
        } catch (Throwable) {
            return null;
        }
    }
}
