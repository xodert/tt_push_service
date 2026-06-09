<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message as KafkaMessage;
use Throwable;

final class KafkaNotificationPublisher
{
    /** @param iterable<int, string> $notificationIds */
    public function publishMany(iterable $notificationIds, string $topic): void
    {
        $producer = Kafka::asyncPublish($this->brokers())->onTopic($topic);

        foreach ($notificationIds as $notificationId) {
            $producer->withMessage(
                KafkaMessage::create()->withBody(['notification_id' => $notificationId])
            )->send();
        }

        $producer->build()->flush();
    }

    public function publishToDlq(ConsumerMessage $message, string $reason): void
    {
        Log::warning('[Kafka] Malformed message routed to DLQ', [
            'topic'     => $message->getTopicName(),
            'partition' => $message->getPartition(),
            'offset'    => $message->getOffset(),
            'body'      => $message->getBody(),
            'reason'    => $reason,
        ]);

        $dlqTopic = config('kafka.dlq_topic');

        if (! is_string($dlqTopic) || $dlqTopic === '') {
            return;
        }

        try {
            Kafka::publish($this->brokers())
                ->onTopic($dlqTopic)
                ->withMessage(
                    KafkaMessage::create()
                        ->withBody((array) $message->getBody())
                        ->withHeaders(array_merge(
                            (array) $message->getHeaders(),
                            [
                                'x-original-topic' => $message->getTopicName(),
                                'x-reason'         => $reason,
                            ]
                        ))
                )
                ->send();
        } catch (Throwable $e) {
            Log::error('[Kafka] Failed to publish to DLQ', [
                'dlq_topic' => $dlqTopic,
                'error'     => $e->getMessage(),
            ]);

            report($e);
        }
    }

    private function brokers(): string
    {
        $brokersRaw = config('kafka.brokers', 'localhost:9092');

        return is_string($brokersRaw) ? $brokersRaw : 'localhost:9092';
    }
}
