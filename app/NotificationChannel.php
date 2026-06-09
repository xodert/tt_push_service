<?php

declare(strict_types=1);

namespace App;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
