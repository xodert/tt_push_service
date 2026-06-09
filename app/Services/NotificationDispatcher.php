<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\NotificationChannel;
use App\NotificationStatus;
use App\NotificationType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class NotificationDispatcher
{
    private const int IDEMPOTENCY_TTL = 86400;

    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly KafkaNotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<int, string>  $recipientIds
     * @return array{batch_id: string, duplicate: bool}
     */
    public function dispatch(
        User $user,
        string $idempotencyKey,
        NotificationType $type,
        NotificationChannel $channel,
        string $message,
        array $recipientIds,
    ): array {
        $batchUuid = Str::uuid()->toString();

        // ключ идемпотентности на пользователя
        $cacheKey = sprintf('idempotency:%d:%s', $user->id, $idempotencyKey);

        if (! Cache::add($cacheKey, $batchUuid, self::IDEMPOTENCY_TTL)) {
            /** @var string $existingId */
            $existingId = Cache::get($cacheKey);

            return ['batch_id' => $existingId, 'duplicate' => true];
        }

        $priority = $type->priority();

        try {
            $batch = $this->persistBatch($user, $batchUuid, $idempotencyKey, $type, $channel, $message, $recipientIds);
        } catch (UniqueConstraintViolationException) {
            // при потере Redis — fallback на unique в БД
            $existing = NotificationBatch::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            Cache::put($cacheKey, $existing->batch_id, self::IDEMPOTENCY_TTL);

            return ['batch_id' => $existing->batch_id, 'duplicate' => true];
        } catch (Throwable $e) {
            Cache::forget($cacheKey);

            throw $e;
        }

        $this->publishToKafka($batch->id, $priority->kafkaTopic());

        return ['batch_id' => $batchUuid, 'duplicate' => false];
    }

    /** @param array<int, string> $recipientIds */
    private function persistBatch(
        User $user,
        string $batchUuid,
        string $idempotencyKey,
        NotificationType $type,
        NotificationChannel $channel,
        string $message,
        array $recipientIds,
    ): NotificationBatch {
        $priority = $type->priority();

        return DB::transaction(function () use ($user, $batchUuid, $idempotencyKey, $channel, $priority, $message, $recipientIds): NotificationBatch {
            $batch = NotificationBatch::query()->create([
                'batch_id'        => $batchUuid,
                'user_id'         => $user->id,
                'idempotency_key' => $idempotencyKey,
                'channel'         => $channel,
                'type'            => $priority,
                'message'         => $message,
                'total_count'     => count($recipientIds),
            ]);

            $now     = now()->toDateTimeString();
            $records = array_map(fn (string $recipientId): array => [
                'notification_id' => Str::uuid()->toString(),
                'batch_id'        => $batch->id,
                'recipient_id'    => $recipientId,
                'channel'         => $channel->value,
                'type'            => $priority->value,
                'message'         => $message,
                'status'          => NotificationStatus::Queued->value,
                'attempts'        => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ], $recipientIds);

            foreach (array_chunk($records, self::BATCH_SIZE) as $chunk) {
                Notification::query()->insert($chunk);
            }

            return $batch;
        });
    }

    private function publishToKafka(int $batchId, string $topic): void
    {
        $notificationIds = Notification::query()->where('batch_id', $batchId)
            ->select(['id', 'notification_id'])
            ->lazy(self::BATCH_SIZE)
            ->map(fn (Notification $notification): string => $notification->notification_id);

        $this->publisher->publishMany($notificationIds, $topic);
    }
}
