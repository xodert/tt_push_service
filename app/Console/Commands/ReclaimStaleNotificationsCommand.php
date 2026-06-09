<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Notification;
use App\NotificationStatus;
use App\Services\KafkaNotificationPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('notifications:reclaim-stale {--lease-minutes=5 : Minutes before a non-terminal notification is considered stale}')]
#[Description('Re-queue notifications stuck in sent/queued state after worker or publisher failures')]
final class ReclaimStaleNotificationsCommand extends Command
{
    private const int MAX_ATTEMPTS = 3;

    private const int CHUNK_SIZE = 500;

    public function __construct(
        private readonly KafkaNotificationPublisher $publisher,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $staleBefore = now()->subMinutes(max(1, (int) $this->option('lease-minutes')));

        $reclaimed   = $this->reclaimStaleSent($staleBefore);
        $republished = $this->republishStaleQueued($staleBefore);

        $this->info("Reclaimed {$reclaimed} stale sent notification(s), republished {$republished} stale queued notification(s).");
    }

    private function reclaimStaleSent(Carbon $staleBefore): int
    {
        $total = 0;

        Notification::query()
            ->where('status', NotificationStatus::Sent)
            ->where('sent_at', '<', $staleBefore)
            ->select(['id', 'notification_id', 'type', 'attempts'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($staleBefore, &$total): void {
                /** @var Collection<int, Notification> $chunk */
                foreach ($chunk as $notification) {
                    $exhausted = $notification->attempts >= self::MAX_ATTEMPTS;

                    $updated = Notification::query()
                        ->where('id', $notification->id)
                        ->where('status', NotificationStatus::Sent)
                        ->where('sent_at', '<', $staleBefore)
                        ->update($exhausted
                            ? ['status' => NotificationStatus::Rejected, 'error_message' => 'Reclaimed: worker died, max attempts exhausted']
                            : ['status' => NotificationStatus::Queued]);

                    if ($updated === 0) {
                        continue;
                    }

                    if (! $exhausted) {
                        $this->publisher->publishMany([$notification->notification_id], $notification->type->kafkaTopic());
                    }

                    Log::warning('[Reclaim] Stale sent notification recovered', [
                        'notification_id' => $notification->id,
                        'attempts'        => $notification->attempts,
                        'outcome'         => $exhausted ? 'rejected' : 'requeued',
                    ]);

                    $total++;
                }
            });

        return $total;
    }

    private function republishStaleQueued(Carbon $staleBefore): int
    {
        $total = 0;

        Notification::query()
            ->where('status', NotificationStatus::Queued)
            ->where('updated_at', '<', $staleBefore)
            ->select(['id', 'notification_id', 'type', 'updated_at'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use (&$total): void {
                /** @var Collection<string, Collection<int, Notification>> $byTopic */
                $byTopic = $chunk->groupBy(fn (Notification $notification): string => $notification->type->kafkaTopic());

                foreach ($byTopic as $topic => $notifications) {
                    $uuids = $notifications->map(fn (Notification $notification): string => $notification->notification_id);

                    $this->publisher->publishMany($uuids, $topic);

                    // не репаблишить каждую минуту
                    Notification::query()
                        ->whereIn('id', $notifications->map(fn (Notification $notification): int => $notification->id))
                        ->update(['updated_at' => now()]);

                    $total += $uuids->count();
                }
            });

        return $total;
    }
}
