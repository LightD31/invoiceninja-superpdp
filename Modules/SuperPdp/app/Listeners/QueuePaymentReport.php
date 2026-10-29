<?php

namespace Modules\SuperPdp\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\SuperPdp\Jobs\ReportPayment;
use Modules\SuperPdp\Support\Companies;

/** PaymentWasCreated / PaymentCompleted → fr:212 for the invoices it pays. */
class QueuePaymentReport
{
    public function handle(object $event): void
    {
        $payment = $event->payment ?? null;

        if (! $payment || ! config('superpdp.report_payments')) {
            return;
        }

        try {
            if (Companies::allows($payment->company_id)) {
                // Short delay: paymentables are attached after the event on some paths.
                ReportPayment::dispatch($payment->id, $payment->company->db)->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            Log::warning('SUPER PDP: could not queue payment report '.$payment->id.': '.$e->getMessage());
        }
    }
}
