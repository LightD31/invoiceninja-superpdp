<?php

namespace Modules\SuperPdp\Services;

use App\Jobs\Util\UploadFile;
use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\SuperPdp\Exceptions\MappingException;
use Modules\SuperPdp\Exceptions\SuperPdpException;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Support\Activities;

/**
 * Invoice Ninja document → EN16931 JSON + PDF → Factur-X (built by SUPER
 * PDP) → validation → upload.
 */
class Submitter
{
    public function __construct(
        private readonly SuperPdpClient $api,
        private readonly InvoiceMapper $mapper,
        private readonly PdfRenderer $renderer,
        private readonly PaymentReporter $payments,
    ) {}

    /**
     * Build and check the Factur-X without uploading it.
     *
     * @return array{invoice: array, processing_rule: string, vat_breakdown: array, total_with_vat: float, warnings: array, pdf: string, facturx: string, report: ?array}
     *
     * @throws MappingException when the document or its validation report has errors
     * @throws SuperPdpException
     */
    public function prepare(Invoice|Credit $entity, ?bool $validate = null): array
    {
        $mapped = $this->mapper->build($entity);
        $mapped['pdf'] = $this->renderer->render($entity);

        try {
            $mapped['facturx'] = $this->api->convertToFacturX($mapped['invoice'], $mapped['pdf'], $this->filename($entity, 'pdf'));
        } catch (SuperPdpException $e) {
            // A 400 here is a business rule (BR-…) the JSON breaks.
            throw $e->status === 400 ? new MappingException('Refusée par SUPER PDP : '.$this->ruleMessage($e)) : $e;
        }

        $mapped['report'] = null;

        if ($validate ?? config('superpdp.validate')) {
            $mapped['report'] = $this->api->validate($mapped['facturx'], $this->filename($entity, 'pdf'));

            if (! ($mapped['report']['is_valid'] ?? false)) {
                throw new MappingException('Factur-X invalide : '.$this->failures($mapped['report']));
            }
        }

        return $mapped;
    }

    /**
     * Upload a queued document. Business errors are recorded on the row and
     * in the invoice history; transient API errors are rethrown so the job
     * retries.
     */
    public function submit(SuperPdpDocument $doc): void
    {
        $lock = Cache::lock('superpdp:document:'.$doc->id, 900);

        if (! $lock->get()) {
            throw new SuperPdpException('Envoi déjà en cours pour ce document'); // retryable
        }

        try {
            $this->submitLocked($doc->fresh());
        } finally {
            $lock->release();
        }
    }

    private function submitLocked(SuperPdpDocument $doc): void
    {
        $entity = $doc->entity();

        if (! $entity || $entity->is_deleted) {
            $doc->update(['status' => SuperPdpDocument::SKIPPED, 'error' => 'Document supprimé']);

            return;
        }

        if ($doc->isUploaded()) {
            return; // never upload twice
        }

        if (blank($entity->number)) {
            throw new SuperPdpException('Document has no number yet'); // retry later
        }

        $doc->increment('attempts');

        try {
            $this->assertSameCompany($entity);

            // A previous attempt may have reached SUPER PDP before failing.
            if ($doc->attempts > 1 && ($existing = $this->findUploaded(self::externalId($entity)))) {
                $this->recordSent($doc, $entity, $existing, $doc->processing_rule ?? '?', [], null);

                return;
            }

            $prepared = $this->prepare($entity);

            if ($prepared['processing_rule'] === 'B2C' && ! config('superpdp.send_b2c')) {
                $doc->update(['status' => SuperPdpDocument::SKIPPED, 'processing_rule' => 'B2C', 'error' => 'B2C non envoyé (SUPERPDP_SEND_B2C=false)']);

                return;
            }

            $doc->update([
                'number' => $entity->number,
                'processing_rule' => $prepared['processing_rule'],
                'vat_breakdown' => $prepared['vat_breakdown'],
                'total_with_vat' => $prepared['total_with_vat'],
            ]);

            // The directory pre-check can only look up Peppol addresses, not e-mails.
            $emailBuyer = ($prepared['invoice']['buyer']['electronic_address']['scheme'] ?? '') === 'EM';

            $sent = $this->api->sendInvoice($prepared['facturx'], self::externalId($entity), $prepared['processing_rule'], $emailBuyer);
        } catch (MappingException $e) {
            $this->fail($doc, $entity, $e->getMessage());

            return;
        } catch (SuperPdpException $e) {
            if ($e->isRetryable()) {
                $doc->update(['error' => $e->getMessage()]);

                throw $e;
            }

            $this->fail($doc, $entity, $e->status === 400 ? $this->ruleMessage($e) : $e->getMessage());

            return;
        }

        $this->recordSent($doc, $entity, $sent, $prepared['processing_rule'], $prepared['warnings'], $prepared['facturx']);
    }

    /** Called by the job once it gives up on a transient error. */
    public function giveUp(SuperPdpDocument $doc, string $reason): void
    {
        if ($doc->isUploaded() || ! ($entity = $doc->entity())) {
            return;
        }

        $this->fail($doc, $entity, $reason);
    }

    private function recordSent(SuperPdpDocument $doc, Invoice|Credit $entity, array $sent, string $rule, array $warnings, ?string $facturx): void
    {
        $last = collect($sent['events'] ?? [])->last();

        $doc->update([
            'superpdp_id' => $sent['id'],
            'status' => SuperPdpDocument::SENT,
            'number' => $entity->number,
            'error' => null,
            'sent_at' => now(),
            'last_event_code' => $last['status_code'] ?? 'api:uploaded',
            'last_event_text' => $last['status_text'] ?? null,
        ]);

        // Everything below is bookkeeping: a failure must not trigger a re-upload.
        try {
            $notes = "SUPER PDP n° {$sent['id']} ({$rule})";

            if ($warnings) {
                $notes .= ' — '.implode(' ', $warnings);
            }

            Activities::sent($entity, $notes);

            if (config('superpdp.attach_document')) {
                // Found again after a lost response: fetch the stored file.
                $facturx ??= $this->api->invoice((int) $sent['id'], 'original');

                if (str_starts_with($facturx, '%PDF')) {
                    $this->attach($entity, $facturx);
                } else {
                    Log::warning('SUPER PDP: invoice '.$sent['id'].' original is not a PDF, not attached to '.$entity->number);
                }
            }

            if ($entity instanceof Invoice && config('superpdp.report_payments')) {
                $this->payments->reportExisting($entity, $doc->fresh());
            }
        } catch (\Throwable $e) {
            Log::warning('SUPER PDP: post-upload step failed for '.$entity->number.': '.$e->getMessage());
        }
    }

    private function fail(SuperPdpDocument $doc, Invoice|Credit $entity, string $message): void
    {
        $doc->update(['status' => SuperPdpDocument::FAILED, 'error' => $message]);

        Activities::failed($entity, 'SUPER PDP : '.$message);
    }

    /**
     * In production, refuse to upload invoices of another legal entity than
     * the one owning the SUPER PDP account.
     */
    private function assertSameCompany(Invoice|Credit $entity): void
    {
        $me = $this->api->me();

        if (($me['env'] ?? '') !== 'production' || ($me['number_scheme'] ?? '') !== 'fr_siren') {
            return;
        }

        $siren = substr(preg_replace('/\D/', '', (string) $entity->company->settings->id_number), 0, 9);

        if ($siren !== (string) $me['number']) {
            throw new MappingException("Le compte SUPER PDP appartient à {$me['formal_name']} (SIREN {$me['number']}), pas à l'entreprise de ce document (SIREN {$siren}).");
        }
    }

    /**
     * SUPER PDP external_id: "invoice/F2026-0001" or "credit/A2026-0001", so
     * invoices and credits sharing a number can't be confused.
     */
    public static function externalId(Invoice|Credit $entity): string
    {
        return SuperPdpDocument::typeOf($entity).'/'.$entity->number;
    }

    /** Look for an outgoing invoice already uploaded under this external id. */
    private function findUploaded(string $externalId): ?array
    {
        $id = collect($this->api->recentInvoices('out', 100)['data'] ?? [])->firstWhere('external_id', $externalId)['id'] ?? null;

        return $id ? $this->api->invoice((int) $id) : null;
    }

    private function attach(Invoice|Credit $entity, string $facturx): void
    {
        $path = tempnam(sys_get_temp_dir(), 'superpdp');
        file_put_contents($path, $facturx);

        try {
            $file = new UploadedFile($path, $this->filename($entity, 'pdf', '_factur-x'), 'application/pdf', null, true);

            (new UploadFile($file, UploadFile::DOCUMENT, $entity->user, $entity->company, $entity, null, false))->handle();
        } finally {
            @unlink($path);
        }
    }

    private function filename(Invoice|Credit $entity, string $ext, string $suffix = ''): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $entity->number).$suffix.'.'.$ext;
    }

    private function ruleMessage(SuperPdpException $e): string
    {
        $message = json_decode((string) $e->body, true)['message'] ?? $e->getMessage();

        // Drop the XPath that follows " at /*:CrossIndustryInvoice…".
        return trim(preg_replace('/\s+at \/\*:.*$/s', '', $message));
    }

    private function failures(array $report): string
    {
        return collect($report['subreports'] ?? [])
            ->flatMap(fn ($s) => $s['failures'] ?? [])
            ->map(fn ($f) => trim($f['message'] ?? $f['raw'] ?? json_encode($f)))
            ->unique()
            ->take(5)
            ->implode(' | ') ?: ($report['error'] ?? 'raison inconnue');
    }
}
