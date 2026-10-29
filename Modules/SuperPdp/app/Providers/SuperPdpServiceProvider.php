<?php

namespace Modules\SuperPdp\Providers;

use App\Events\Credit\CreditWasMarkedSent;
use App\Events\Credit\CreditWasUpdated;
use App\Events\Invoice\InvoiceWasUpdated;
use App\Events\Payment\PaymentCompleted;
use App\Events\Payment\PaymentWasCreated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\SuperPdp\Console\CheckCommand;
use Modules\SuperPdp\Console\SendCommand;
use Modules\SuperPdp\Console\StatusCommand;
use Modules\SuperPdp\Console\SyncCommand;
use Modules\SuperPdp\Listeners\QueueIssuedDocument;
use Modules\SuperPdp\Listeners\QueuePaymentReport;
use Modules\SuperPdp\Services\SuperPdpClient;

class SuperPdpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'superpdp');

        $this->app->singleton(SuperPdpClient::class, fn () => new SuperPdpClient(
            config('superpdp.base_url'),
            config('superpdp.client_id'),
            config('superpdp.client_secret'),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, SendCommand::class, StatusCommand::class, SyncCommand::class]);
        }

        if (! config('superpdp.enabled')) {
            return;
        }

        Event::listen(InvoiceWasUpdated::class, QueueIssuedDocument::class);
        Event::listen(CreditWasMarkedSent::class, QueueIssuedDocument::class);
        Event::listen(CreditWasUpdated::class, QueueIssuedDocument::class);
        Event::listen(PaymentWasCreated::class, QueuePaymentReport::class);
        Event::listen(PaymentCompleted::class, QueuePaymentReport::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('superpdp:sync')
                ->cron(config('superpdp.sync_cron'))
                ->withoutOverlapping(30)
                ->runInBackground();
        });
    }
}
