<?php

declare(strict_types=1);

namespace App\Models;

use App\NotificationChannel;
use App\NotificationPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $batch_id
 * @property int $user_id
 * @property string $idempotency_key
 * @property NotificationChannel $channel
 * @property NotificationPriority $type
 * @property string $message
 * @property int $total_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'batch_id',
    'user_id',
    'idempotency_key',
    'channel',
    'type',
    'message',
    'total_count',
])]
final class NotificationBatch extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'type'    => NotificationPriority::class,
        ];
    }
}
