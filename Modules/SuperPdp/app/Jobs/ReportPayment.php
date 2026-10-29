<?php

namespace Modules\SuperPdp\Jobs;

use App\Libraries\MultiDB;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\SuperPdp\Services\PaymentReporter;

class ReportPayment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $paymentId, public string $db) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(PaymentReporter $reporter): void
    {
        MultiDB::setDb($this->db);

        if ($payment = Payment::withTrashed()->find($this->paymentId)) {
            $reporter->reportPayment($payment);
        }
    }
}
