<?php

namespace Modules\SuperPdp\Console;

use Illuminate\Console\Command;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Models\SuperPdpIncoming;
use Modules\SuperPdp\Models\SuperPdpPaymentReport;
use Modules\SuperPdp\Support\State;

class StatusCommand extends Command
{
    protected $signature = 'superpdp:status {--limit=20}';

    protected $description = 'Show what the SUPER PDP module sent, reported and imported';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info('Documents');
        $this->table(
            ['Number', 'Type', 'Rule', 'Status', 'SUPER PDP', 'Last event', 'Error', 'Updated'],
            SuperPdpDocument::latest('updated_at')->limit($limit)->get()->map(fn ($d) => [
                $d->number, $d->entity_type, $d->processing_rule, $d->status, $d->superpdp_id,
                $d->last_event_code, mb_strimwidth((string) $d->error, 0, 60, '…'), $d->updated_at,
            ]),
        );

        $this->info('Payments (fr:212)');
        $this->table(
            ['Payment', 'Invoice', 'Amount', 'Paid on', 'Status', 'Error'],
            SuperPdpPaymentReport::latest('updated_at')->limit($limit)->get()->map(fn ($p) => [
                $p->payment_id, $p->invoice_id, $p->amount, $p->paid_on?->toDateString(), $p->status, mb_strimwidth((string) $p->error, 0, 60, '…'),
            ]),
        );

        $this->info('Received invoices');
        $this->table(
            ['SUPER PDP', 'Number', 'Seller', 'Expense', 'Status', 'Error'],
            SuperPdpIncoming::latest('updated_at')->limit($limit)->get()->map(fn ($i) => [
                $i->superpdp_id, $i->number, $i->seller_name, $i->expense_id, $i->status, mb_strimwidth((string) $i->error, 0, 60, '…'),
            ]),
        );

        $this->line(sprintf('Start date %s, events cursor %s, incoming cursor %s',
            State::startDate(), State::get('events_cursor', '0'), State::get('incoming_cursor', '0')));

        return self::SUCCESS;
    }
}
