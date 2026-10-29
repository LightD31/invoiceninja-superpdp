<?php

namespace Modules\SuperPdp\Console;

use Illuminate\Console\Command;
use Modules\SuperPdp\Services\DocumentQueue;
use Modules\SuperPdp\Services\SuperPdpClient;
use Modules\SuperPdp\Services\Synchronizer;
use Modules\SuperPdp\Support\Companies;

class SyncCommand extends Command
{
    protected $signature = 'superpdp:sync
        {--sweep : Only queue issued documents not handed over yet}
        {--events : Only fetch lifecycle events}
        {--incoming : Only import received invoices}
        {--retry-failed : Also retry received invoices whose import failed}';

    protected $description = 'Fetch SUPER PDP statuses and received invoices (scheduled every 5 minutes)';

    public function handle(SuperPdpClient $api, Synchronizer $sync, DocumentQueue $queue): int
    {
        if (! $api->configured()) {
            $this->error('SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET are not set.');

            return self::FAILURE;
        }

        $only = array_filter(['sweep' => $this->option('sweep'), 'events' => $this->option('events'), 'incoming' => $this->option('incoming')]);
        $run = fn (string $step) => $only === [] || isset($only[$step]);
        $ok = true;

        $steps = [
            'sweep' => fn () => $queue->sweep().' document(s) queued',
            'events' => fn () => $sync->events().' event(s) applied',
            'incoming' => function () use ($sync) {
                if (! config('superpdp.import_incoming')) {
                    return 'disabled (SUPERPDP_IMPORT_INCOMING=false)';
                }

                if (! $company = Companies::target()) {
                    return 'skipped: set SUPERPDP_COMPANY_KEY';
                }

                return $sync->incoming($company, (bool) $this->option('retry-failed')).' invoice(s) imported';
            },
        ];

        foreach ($steps as $name => $step) {
            if (! $run($name)) {
                continue;
            }

            try {
                $this->line("{$name}: ".$step());
            } catch (\Throwable $e) {
                $this->error("{$name}: ".$e->getMessage());
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
