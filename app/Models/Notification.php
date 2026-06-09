<?php

declare(strict_types=1);

namespace App\Models;

use App\NotificationChannel;
use App\NotificationPriority;
use App\NotificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $notification_id
 * @property int $batch_id
 * @property string $recipient_id
 * @property NotificationChannel $channel
 * @property NotificationPriority $type
 * @property string $message
 * @property NotificationStatus $status
 * @property string|null $error_message
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'notification_id',
    'batch_id',
    'recipient_id',
    'channel',
    'type',
    'message',
    'status',
    'error_message',
    'attempts',
    'sent_at',
    'delivered_at',
])]
final class Notification extends Model
{
    /** @return BelongsTo<NotificationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel'      => NotificationChannel::class,
            'type'         => NotificationPriority::class,
            'status'       => NotificationStatus::class,
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
