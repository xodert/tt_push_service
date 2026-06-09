<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkNotificationRequest;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\NotificationChannel;
use App\NotificationStatus;
use App\NotificationType;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher
    ) {}

    public function bulkSend(BulkNotificationRequest $request): JsonResponse
    {
        $user = $request->authenticatedUser();
        $type = $request->type();

        if ($type === NotificationType::Transactional && ! $user->can_send_transactional) {
            abort(403, 'This user is not allowed to send transactional notifications.');
        }

        $result = $this->dispatcher->dispatch(
            user: $user,
            idempotencyKey: $request->idempotencyKey(),
            type: $type,
            channel: NotificationChannel::from($request->string('channel')->toString()),
            message: $request->string('message')->toString(),
            recipientIds: $request->recipientIds(),
        );

        $statusCode = $result['duplicate'] ? 200 : 202;

        return response()->json([
            'batch_id'  => $result['batch_id'],
            'duplicate' => $result['duplicate'],
        ], $statusCode);
    }

    public function subscriberHistory(Request $request, string $recipientId): JsonResponse
    {
        $notifications = Notification::query()->where('recipient_id', $recipientId)
            ->select(['notification_id', 'channel', 'type', 'status', 'error_message', 'attempts', 'sent_at', 'delivered_at', 'created_at'])->latest()
            ->paginate(50);

        return response()->json($notifications);
    }

    public function batchStatus(string $batchId): JsonResponse
    {
        $batch = NotificationBatch::query()->where('batch_id', $batchId)->firstOrFail();

        /** @var array<string, int> $counts */
        $counts = Notification::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return response()->json([
            'batch_id'        => $batch->batch_id,
            'channel'         => $batch->channel,
            'type'            => $batch->type,
            'total_count'     => $batch->total_count,
            'queued_count'    => $counts[NotificationStatus::Queued->value] ?? 0,
            'sent_count'      => $counts[NotificationStatus::Sent->value] ?? 0,
            'delivered_count' => $counts[NotificationStatus::Delivered->value] ?? 0,
            'rejected_count'  => $counts[NotificationStatus::Rejected->value] ?? 0,
            'created_at'      => $batch->created_at,
        ]);
    }
}
