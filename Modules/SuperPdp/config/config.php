<?php

return [

    /*
    | Master switch. When false the module only registers its artisan
    | commands (so `superpdp:send --dry-run` still works) and never calls
    | SUPER PDP on its own.
    */
    'enabled' => env('SUPERPDP_ENABLED', false),

    'base_url' => rtrim(env('SUPERPDP_BASE_URL', 'https://api.superpdp.tech'), '/'),

    // OAuth2 client credentials of a SUPER PDP "Application". The pair
    // decides sandbox vs production.
    'client_id' => env('SUPERPDP_CLIENT_ID'),
    'client_secret' => env('SUPERPDP_CLIENT_SECRET'),

    // Invoice Ninja company_key the SUPER PDP account belongs to. Required
    // when the instance has more than one company.
    'company_key' => env('SUPERPDP_COMPANY_KEY'),

    // Documents dated before this day are never sent automatically and
    // received invoices created before it are not imported. Empty = the day
    // the module was installed (stored by its migration).
    'start_date' => env('SUPERPDP_START_DATE'),

    /*
    | Behaviour
    */
    'auto_send' => env('SUPERPDP_AUTO_SEND', true),
    // Seconds between "marked sent" and the upload, so the number, due date
    // and invitations are settled first.
    'send_delay' => (int) env('SUPERPDP_SEND_DELAY', 60),
    // Run the Factur-X through /validation_reports before uploading it.
    'validate' => env('SUPERPDP_VALIDATE', true),
    // B2C invoices go to SUPER PDP for e-reporting only (never delivered).
    'send_b2c' => env('SUPERPDP_SEND_B2C', true),
    // Send the "Encaissée" (fr:212) status when a payment is recorded.
    'report_payments' => env('SUPERPDP_REPORT_PAYMENTS', true),
    // Import received invoices as expenses.
    'import_incoming' => env('SUPERPDP_IMPORT_INCOMING', true),
    // Keep a private copy of the uploaded Factur-X on the invoice.
    'attach_document' => env('SUPERPDP_ATTACH_DOCUMENT', true),
    'sync_cron' => env('SUPERPDP_SYNC_CRON', '*/5 * * * *'),

    /*
    | Invoice content (XP Z12-012)
    */
    // BT-23 "cadre de facturation": B (goods), S (services) or M (mixed).
    // The module appends 1, or 2 when the invoice is already paid.
    'business_process' => strtoupper(env('SUPERPDP_BUSINESS_PROCESS', 'S')),

    // Franchise en base (art. 293 B): every line is VAT category E with
    // VATEX-FR-FRANCHISE. Empty = automatic (company has no VAT number).
    'vat_franchise' => env('SUPERPDP_VAT_FRANCHISE'),
    'vat_franchise_text' => env('SUPERPDP_VAT_FRANCHISE_TEXT', 'TVA non applicable, art. 293 B du CGI'),
    // Reason printed for lines whose product tax category is "exempt", or
    // for tax-exempt clients, when the company is not in franchise.
    'vat_exempt_text' => env('SUPERPDP_VAT_EXEMPT_TEXT', 'Exonération de TVA'),

    // Seller electronic address (BT-34, scheme 0225). Empty = the SIREN.
    'seller_address' => env('SUPERPDP_SELLER_ADDRESS'),

    'iban' => env('SUPERPDP_IBAN'),
    'bic' => env('SUPERPDP_BIC'),

    // BT-20, used when the document has no due date.
    'payment_terms' => env('SUPERPDP_PAYMENT_TERMS', 'Paiement à réception de facture'),

    // Mandatory notes (BR-FR-05).
    'notes' => [
        'PMT' => env('SUPERPDP_NOTE_PMT', 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €'),
        'PMD' => env('SUPERPDP_NOTE_PMD', 'Pénalités de retard : trois fois le taux d\'intérêt légal'),
        'AAB' => env('SUPERPDP_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé'),
    ],

    // Invoice custom field holding the date of the service/delivery
    // (invoice1..invoice4). Accepts 2026-10-28, 28/10/2026 or a range
    // "01/10/2026 - 15/10/2026".
    'delivery_date_field' => env('SUPERPDP_DELIVERY_DATE_FIELD'),
];
