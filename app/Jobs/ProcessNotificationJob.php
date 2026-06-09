<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\NotificationChannel;
use App\NotificationStatus;
use App\Services\Gateway\GatewayInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Backoff(60)]
#[Tries(3)]
final class ProcessNotificationJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $notificationId,
    ) {}

    public function handle(): void
    {
        // claim: только один воркер обработает, повтор из Kafka — no-op
        $claimed = Notification::query()
            ->where('notification_id', $this->notificationId)
            ->where('status', NotificationStatus::Queued)
            ->update([
                'status'   => NotificationStatus::Sent,
                'sent_at'  => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return;
        }

        $notification = Notification::query()->where('notification_id', $this->notificationId)->firstOrFail();

        $gateway = $this->gateway($notification->channel);
        $result  = $gateway->send($notification->recipient_id, $notification->message);

        if ($result->success) {
            $notification->update(['status' => NotificationStatus::Delivered, 'delivered_at' => now()]);

            return;
        }

        if ($result->permanent) {
            $notification->update(['status' => NotificationStatus::Rejected, 'error_message' => $result->error]);

            return;
        }

        $notification->update(['status' => NotificationStatus::Queued]);

        throw new RuntimeException($result->error ?? 'Gateway temporary failure');
    }

    public function failed(Throwable $e): void
    {
        Notification::query()
            ->where('notification_id', $this->notificationId)
            ->whereIn('status', [NotificationStatus::Queued, NotificationStatus::Sent])
            ->update([
                'status'        => NotificationStatus::Rejected,
                'error_message' => $e->getMessage(),
            ]);
    }

    private function gateway(NotificationChannel $channel): GatewayInterface
    {
        return match ($channel) {
            NotificationChannel::Sms   => resolve('gateway.sms'),
            NotificationChannel::Email => resolve('gateway.email'),
        };
    }
}
