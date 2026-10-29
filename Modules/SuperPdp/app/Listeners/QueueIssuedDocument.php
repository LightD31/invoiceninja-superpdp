<?php

namespace Modules\SuperPdp\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\SuperPdp\Services\DocumentQueue;

/**
 * InvoiceWasUpdated / CreditWasMarkedSent / CreditWasUpdated. Invoice
 * Ninja saves the "sent" status quietly (no model events), but fires
 * InvoiceWasUpdated once the number and due date are applied.
 */
class QueueIssuedDocument
{
    public function __construct(private readonly DocumentQueue $queue) {}

    public function handle(object $event): void
    {
        $entity = $event->invoice ?? $event->credit ?? null;

        if (! $entity) {
            return;
        }

        // Never break the request that triggered the event.
        try {
            $this->queue->queueIfEligible($entity);
        } catch (\Throwable $e) {
            Log::warning('SUPER PDP: could not queue '.$entity->number.': '.$e->getMessage());
        }
    }
}
