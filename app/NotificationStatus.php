<?php

declare(strict_types=1);

namespace App;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
}
