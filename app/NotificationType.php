<?php

declare(strict_types=1);

namespace App;

enum NotificationType: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function priority(): NotificationPriority
    {
        return match ($this) {
            self::Transactional => NotificationPriority::High,
            self::Marketing     => NotificationPriority::Low,
        };
    }
}
