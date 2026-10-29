<?php

namespace Modules\SuperPdp\Services;

use App\Jobs\EDocument\ImportEDocument;
use App\Jobs\Util\UploadFile;
use App\Models\Company;
use App\Models\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Models\SuperPdpIncoming;
use Modules\SuperPdp\Support\Activities;
use Modules\SuperPdp\Support\State;

/**
 * Polls SUPER PDP: lifecycle events of uploaded documents, and invoices
 * received from suppliers. Both lists use strictly increasing ids, so a
 * stored cursor guarantees nothing is missed.
 */
class Synchronizer
{
    /** Events that end the document's journey in failure. */
    private const FAILURES = ['api:invalid', 'api:rejected', 'fr:210', 'fr:213', 'fr:501'];

    /** Events meaning the buyer has the invoice. */
    private const DELIVERED = ['fr:203', 'api:accepted'];

    /** Events already written to the history by the module itself. */
    private const OWN = ['api:uploaded', 'fr:212'];

    public function __construct(private readonly SuperPdpClient $api) {}

    public function events(): int
    {
        $after = (int) State::get('events_cursor', '0');
        $applied = 0;

        do {
            $page = $this->api->events($after, 500);

            foreach ($page['data'] ?? [] as $event) {
                $applied += $this->apply($event) ? 1 : 0;
                $after = max($after, (int) $event['id']);
            }

            State::put('events_cursor', $after);
        } while ($page['has_after'] ?? false);

        return $applied;
    }

    private function apply(array $event): bool
    {
        $doc = SuperPdpDocument::where('superpdp_id', $event['invoice_id'])->first();

        if (! $doc || ! ($entity = $doc->entity())) {
            return false;
        }

        $code = (string) $event['status_code'];
        $text = trim(($event['status_text'] ?? $code).' ('.$code.')');

        if ($reason = $event['data']['reason'] ?? collect($event['details'] ?? [])->pluck('reason')->filter()->implode(', ')) {
            $text .= ' — '.$reason;
        }

        $failure = in_array($code, self::FAILURES, true) || preg_match('/^ppf:.*-(ack-error|rejected)$/', $code);
        $delivered = in_array($code, self::DELIVERED, true);

        $doc->update([
            'last_event_code' => $code,
            'last_event_text' => mb_substr($event['status_text'] ?? '', 0, 255),
            'status' => match (true) {
                $failure => SuperPdpDocument::REJECTED,
                $delivered && $doc->status === SuperPdpDocument::SENT => SuperPdpDocument::DELIVERED,
                default => $doc->status,
            },
            'error' => $failure ? $text : $doc->error,
        ]);

        // PPF acknowledgements are technical noise unless they fail.
        if (in_array($code, self::OWN, true) || (str_starts_with($code, 'ppf:') && ! $failure)) {
            return true;
        }

        match (true) {
            $failure => Activities::failed($entity, 'SUPER PDP : '.$text),
            $delivered => Activities::delivered($entity, 'SUPER PDP : '.$text),
            default => Activities::status($entity, $text),
        };

        return true;
    }

    /** Import received invoices (Factur-X) as expenses of $company. */
    public function incoming(Company $company, bool $retryFailed = false): int
    {
        $imported = 0;

        if ($retryFailed) {
            foreach (SuperPdpIncoming::where('status', 'failed')->get() as $row) {
                $imported += $this->import($company, (int) $row->superpdp_id, $row->number, $row->seller_name) ? 1 : 0;
            }
        }

        $after = (int) State::get('incoming_cursor', '0');
        $start = State::startDate();

        do {
            $page = $this->api->invoices('in', $after, 100);

            foreach ($page['data'] ?? [] as $invoice) {
                $after = max($after, (int) $invoice['id']);

                if (substr((string) $invoice['created_at'], 0, 10) < $start || SuperPdpIncoming::where('superpdp_id', $invoice['id'])->exists()) {
                    continue;
                }

                $imported += $this->import(
                    $company,
                    (int) $invoice['id'],
                    $invoice['en_invoice']['number'] ?? null,
                    $invoice['en_invoice']['seller']['name'] ?? null,
                ) ? 1 : 0;
            }

            State::put('incoming_cursor', $after);
        } while ($page['has_after'] ?? false);

        return $imported;
    }

    private function import(Company $company, int $id, ?string $number, ?string $seller): bool
    {
        $row = SuperPdpIncoming::firstOrNew(['superpdp_id' => $id]);
        $row->fill(['company_id' => $company->id, 'number' => $number, 'seller_name' => $seller]);

        try {
            $pdf = $this->api->invoice($id, 'factur-x');
            $name = 'superpdp-'.$id.'-'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $number).'.pdf';

            // Invoice Ninja's importer creates the expense before it finishes
            // parsing; roll back so a failure leaves no empty expense behind.
            $expense = DB::transaction(function () use ($company, $pdf, $name) {
                $expense = $this->tolerateReaderWarnings(fn () => (new ImportEDocument($pdf, $name, 'application/pdf', $company))->handle());
                $this->attachIfMissing($expense, $pdf, $name);

                return $expense;
            });

            $row->fill(['expense_id' => $expense->id, 'status' => 'imported', 'error' => null])->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning("SUPER PDP: import of received invoice {$id} failed: ".$e->getMessage());
            $row->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            return false;
        }
    }

    /**
     * Invoice Ninja's Factur-X import reads optional parts (e.g. the seller
     * contact, BG-6) without checking they exist; horstoeko then raises
     * "Undefined array key" warnings, which Laravel turns into exceptions
     * and the whole import fails. Ignore warnings coming from horstoeko only.
     */
    private function tolerateReaderWarnings(callable $callback): mixed
    {
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (str_contains($file, '/horstoeko/')) {
                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /** Keep the received Factur-X on the expense (the legal original). */
    private function attachIfMissing(Expense $expense, string $pdf, string $name): void
    {
        if ($expense->documents()->exists()) {
            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'superpdp');
        file_put_contents($path, $pdf);

        try {
            $file = new UploadedFile($path, $name, 'application/pdf', null, true);
            (new UploadFile($file, UploadFile::DOCUMENT, $expense->user, $expense->company, $expense, null, false))->handle();
        } finally {
            @unlink($path);
        }
    }
}
