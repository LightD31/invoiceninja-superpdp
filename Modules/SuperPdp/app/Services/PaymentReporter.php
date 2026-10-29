<?php

namespace Modules\SuperPdp\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SuperPdp\Exceptions\SuperPdpException;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Models\SuperPdpPaymentReport;
use Modules\SuperPdp\Support\Activities;

/**
 * Sends the "Encaissée" lifecycle status (fr:212) for payments, which is
 * what feeds the payment e-reporting (services, B2C invoices).
 */
class PaymentReporter
{
    private const REPORTABLE = [Payment::STATUS_COMPLETED, Payment::STATUS_PARTIALLY_REFUNDED];

    public function __construct(private readonly SuperPdpClient $api) {}

    /** A new payment: report it for every uploaded invoice it pays. */
    public function reportPayment(Payment $payment): void
    {
        if ($payment->is_deleted || ! in_array($payment->status_id, self::REPORTABLE)) {
            return;
        }

        foreach ($payment->invoices as $invoice) {
            $doc = SuperPdpDocument::for($invoice);

            // Not uploaded yet: reportExisting() picks it up after the upload.
            if ($doc?->isUploaded()) {
                $this->report($payment, $invoice, $doc, (float) $invoice->pivot->amount - (float) $invoice->pivot->refunded);
            }
        }
    }

    /** Payments recorded before the invoice reached SUPER PDP. */
    public function reportExisting(Invoice $invoice, SuperPdpDocument $doc): void
    {
        foreach ($invoice->payments()->where('is_deleted', false)->whereIn('status_id', self::REPORTABLE)->get() as $payment) {
            $this->report($payment, $invoice, $doc, (float) $payment->pivot->amount - (float) $payment->pivot->refunded);
        }
    }

    private function report(Payment $payment, Invoice $invoice, SuperPdpDocument $doc, float $amount): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        $date = \Carbon\Carbon::parse($payment->date ?: now())->toDateString();
        $currency = $invoice->client->currency()->code ?? 'EUR';

        // Claim the (payment, invoice) pair first so two workers can't both
        // declare it. A row left in "sending" (crash mid-call) is never
        // retried automatically: a duplicate fr:212 would double the e-reporting.
        if (! $row = $this->claim($payment, $invoice, $doc, $amount, $date)) {
            return;
        }

        try {
            $event = $this->api->createEvent([
                'invoice_id' => $doc->superpdp_id,
                'status_code' => 'fr:212',
                'details' => [['reported_data' => $this->split($doc, $amount, $currency, $date)]],
            ]);
        } catch (SuperPdpException $e) {
            $row->update(['status' => 'failed', 'error' => $e->getMessage()]);

            if ($e->isRetryable()) {
                throw $e;
            }

            Activities::status($invoice, "échec de la déclaration d'encaissement (fr:212) de {$amount} {$currency} : ".$e->getMessage());

            return;
        }

        $row->update(['status' => 'sent', 'superpdp_event_id' => $event['id'] ?? null, 'error' => null]);
        Activities::status($invoice, "Encaissée — {$amount} {$currency} le {$date} déclarée à SUPER PDP (fr:212)");
    }

    private function claim(Payment $payment, Invoice $invoice, SuperPdpDocument $doc, float $amount, string $date): ?SuperPdpPaymentReport
    {
        $match = ['payment_id' => $payment->id, 'invoice_id' => $invoice->id];

        // Retry of a failed attempt.
        if (SuperPdpPaymentReport::where($match)->where('status', 'failed')->update(['status' => 'sending']) === 1) {
            return SuperPdpPaymentReport::where($match)->first();
        }

        try {
            return SuperPdpPaymentReport::create($match + [
                'company_id' => $invoice->company_id,
                'superpdp_invoice_id' => $doc->superpdp_id,
                'amount' => $amount,
                'paid_on' => $date,
                'status' => 'sending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // already sent or being sent
        }
    }

    /**
     * MDG-43 data: the amount received per VAT rate, in proportion to the
     * invoice's VAT groups.
     */
    private function split(SuperPdpDocument $doc, float $amount, string $currency, string $date): array
    {
        $groups = $doc->vat_breakdown ?: [['rate' => '0.00', 'taxable' => $amount, 'tax' => 0]];
        $total = array_sum(array_map(fn ($g) => (float) $g['taxable'] + (float) $g['tax'], $groups)) ?: $amount;
        $left = $amount;
        $data = [];

        foreach (array_values($groups) as $i => $g) {
            $part = $i === count($groups) - 1 ? $left : round($amount * ((float) $g['taxable'] + (float) $g['tax']) / $total, 2);
            $left = round($left - $part, 2);

            $data[] = [
                'amount' => number_format($part, 2, '.', ''),
                'currency_code' => $currency,
                'type_code' => 'MEN',
                'value_percent' => number_format((float) $g['rate'], 2, '.', ''),
                'date' => $date,
            ];
        }

        return $data;
    }
}
