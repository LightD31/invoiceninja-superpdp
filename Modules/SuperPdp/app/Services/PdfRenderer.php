<?php

namespace Modules\SuperPdp\Services;

use App\Models\Credit;
use App\Models\Invoice;
use App\Services\Pdf\PdfService;
use Modules\SuperPdp\Exceptions\MappingException;

/**
 * Renders the document with its usual design, without Invoice Ninja's own
 * Factur-X XML: SUPER PDP embeds the XML built from our JSON instead.
 */
class PdfRenderer
{
    public function render(Invoice|Credit $entity): string
    {
        $invitation = $entity->invitations()->first()
            ?? throw new MappingException('Le document n\'a pas d\'invitation (aucun contact client) : impossible de générer le PDF.');

        $service = new PdfService($invitation, 'product', [
            'client' => $entity->client,
            ($entity instanceof Credit ? 'credits' : 'invoices') => [$entity],
            'skip_e_invoice_pdf_merge' => true,
        ]);

        return $service->boot()->getPdf();
    }
}
