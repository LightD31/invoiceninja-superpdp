<?php

namespace Modules\SuperPdp\Services;

use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SuperPdp\Jobs\SubmitDocument;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Support\Companies;
use Modules\SuperPdp\Support\State;

/** Decides which issued documents go to SUPER PDP and queues them once. */
class DocumentQueue
{
    /** Statuses meaning "issued to the client" (sent, partial, paid/applied). */
    public const ISSUED = [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID];

    public function queueIfEligible(Invoice|Credit $entity): ?SuperPdpDocument
    {
        if (! $this->eligible($entity)) {
            return null;
        }

        return $this->queue($entity, config('superpdp.send_delay'));
    }

    /** Create the row and dispatch the upload, unless the document already has a row. */
    public function queue(Invoice|Credit $entity, int $delay = 0): SuperPdpDocument
    {
        try {
            $doc = SuperPdpDocument::create([
                'company_id' => $entity->company_id,
                'entity_type' => SuperPdpDocument::typeOf($entity),
                'entity_id' => $entity->id,
                'number' => $entity->number,
                'status' => SuperPdpDocument::QUEUED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return SuperPdpDocument::for($entity);
        }

        SubmitDocument::dispatch($doc->id, $entity->company->db)->delay(now()->addSeconds(max(0, $delay)));

        return $doc;
    }

    public function eligible(Invoice|Credit $entity): bool
    {
        return config('superpdp.enabled')
            && config('superpdp.auto_send')
            && ! $entity->is_deleted
            && in_array((int) $entity->status_id, self::ISSUED, true)
            && filled($entity->date)
            && \Carbon\Carbon::parse($entity->date)->toDateString() >= State::startDate()
            && Companies::allows($entity->company_id)
            && ! SuperPdpDocument::for($entity);
    }

    /**
     * Issued documents the event listeners missed (API imports, bulk
     * actions…).
     */
    public function sweep(int $limit = 50): int
    {
        if (! config('superpdp.enabled') || ! config('superpdp.auto_send') || ! ($company = Companies::target())) {
            return 0;
        }

        $count = 0;

        foreach ([Invoice::class => 'invoice', Credit::class => 'credit'] as $class => $type) {
            $class::query()
                ->where('company_id', $company->id)
                ->where('is_deleted', false)
                ->whereIn('status_id', self::ISSUED)
                ->where('date', '>=', State::startDate())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('superpdp_documents')
                    ->where('superpdp_documents.entity_type', $type)
                    ->whereColumn('superpdp_documents.entity_id', (new $class)->getTable().'.id'))
                ->limit($limit)
                ->get()
                ->each(function ($entity) use (&$count) {
                    $this->queue($entity, config('superpdp.send_delay'));
                    $count++;
                });
        }

        return $count;
    }
}
