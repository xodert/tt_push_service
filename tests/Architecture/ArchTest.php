<?php

declare(strict_types=1);

use App\Console\Commands\ConsumeNotificationsCommand;
use App\Http\Requests\BulkNotificationRequest;
use App\NotificationStatus;
use App\Services\Gateway\GatewayInterface;
use App\Services\Gateway\GatewayResult;
use App\Services\Gateway\MockSmsGateway;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

arch('no debug functions are used')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('strict types are declared in all files')
    ->expect('App')
    ->toUseStrictTypes();

arch('models extend Eloquent Model')
    ->expect('App\Models')
    ->toExtend(Model::class);

arch('models are final')
    ->expect('App\Models')
    ->toBeFinal();

arch('services are final')
    ->expect('App\Services')
    ->toBeFinal()
    ->ignoring(GatewayInterface::class);

arch('services are not coupled to the HTTP layer')
    ->expect('App\Services')
    ->not->toUse(Request::class)
    ->not->toUse(Response::class);

arch('console commands extend Command')
    ->expect('App\Console\Commands')
    ->toExtend(Command::class);

arch('console commands are final')
    ->expect('App\Console\Commands')
    ->toBeFinal();

arch('form requests extend FormRequest')
    ->expect('App\Http\Requests')
    ->toExtend(FormRequest::class);

arch('form requests are final')
    ->expect('App\Http\Requests')
    ->toBeFinal();

arch('jobs are final')
    ->expect('App\Jobs')
    ->toBeFinal();

arch('gateway mocks implement GatewayInterface')
    ->expect('App\Services\Gateway')
    ->toImplement(GatewayInterface::class)
    ->ignoring(GatewayInterface::class)
    ->ignoring(GatewayResult::class);

arch('gateway concrete classes are final')
    ->expect(MockSmsGateway::class)
    ->toBeFinal();

arch('gateway result is final')
    ->expect(GatewayResult::class)
    ->toBeFinal();

arch('ConsumeNotificationsCommand exists and extends Command')
    ->expect(ConsumeNotificationsCommand::class)
    ->toExtend(Command::class);

arch('BulkNotificationRequest exists and extends FormRequest')
    ->expect(BulkNotificationRequest::class)
    ->toExtend(FormRequest::class);

arch('enums use strict types')
    ->expect(NotificationStatus::class)
    ->toUseStrictTypes();
