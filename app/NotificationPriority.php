<?php

declare(strict_types=1);

namespace App;

enum NotificationPriority: string
{
    case High = 'high';
    case Low = 'low';

    public static function fromKafkaTopic(string $topic): self
    {
        return $topic === self::High->kafkaTopic() ? self::High : self::Low;
    }

    public function kafkaTopic(): string
    {
        return match ($this) {
            self::High => 'notifications_high',
            self::Low  => 'notifications_low',
        };
    }

    /** Laravel queue name used by the queue worker (`--queue=high,low`). */
    public function queue(): string
    {
        return $this->value;
    }
}
