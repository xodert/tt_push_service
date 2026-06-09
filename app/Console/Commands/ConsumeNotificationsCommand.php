<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\NotificationMessageHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;

#[Signature('notifications:consume {--topic=* : Topics to consume (default: both)}')]
#[Description('Consume notification messages from Kafka and dispatch processing jobs')]
final class ConsumeNotificationsCommand extends Command
{
    public function handle(NotificationMessageHandler $handler): void
    {
        /** @var list<string> $topics */
        $topics = $this->option('topic') ?: ['notifications_high', 'notifications_low'];

        $groupId = 'notification-workers-'.Str::slug(implode('-', $topics));

        $this->info('Starting Kafka consumer for topics: '.implode(', ', $topics));

        Kafka::consumer($topics, $groupId)
            ->withHandler($handler)
            ->build()
            ->consume();
    }
}
