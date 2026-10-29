<?php

namespace Modules\SuperPdp\Services;

use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Invoice;
use Modules\SuperPdp\Exceptions\MappingException;

/**
 * Builds the SUPER PDP `en_invoice` JSON (EN16931 + French CTC rules,
 * XP Z12-012) from an Invoice Ninja invoice or credit.
 *
 * Returned shape:
 *  - invoice: the en_invoice array
 *  - processing_rule: B2B, B2C or B2BInt
 *  - vat_breakdown: [{category, rate, taxable, tax}] used to split payments
 *  - total_with_vat: float
 *  - warnings: string[]
 */
class InvoiceMapper
{
    private const SPECIFICATION = 'urn:cen.eu:en16931:2017';

    /** @var string[] */
    private array $warnings = [];

    public function build(Invoice|Credit $entity): array
    {
        $this->warnings = [];

        $entity->loadMissing(['company', 'client.country', 'client.shipping_country', 'client.contacts']);

        $company = $entity->company;
        $settings = $company->settings;
        $client = $entity->client;
        $isCredit = $entity instanceof Credit;

        if (blank($entity->number)) {
            throw new MappingException('Le document n\'a pas encore de numéro.');
        }

        if (blank($entity->date)) {
            throw new MappingException('Le document n\'a pas de date.');
        }

        $sellerCountry = Country::find($settings->country_id)?->iso_3166_2 ?? 'FR';

        if ($sellerCountry !== 'FR') {
            throw new MappingException("Le module ne gère que les vendeurs établis en France (pays de l'entreprise : {$sellerCountry}).");
        }

        [$sellerSiren, $sellerSiret] = $this->siren((string) $settings->id_number, 'de l\'entreprise (Paramètres → Entreprise → N° d\'identification)');
        $sellerVat = $this->vatNumber((string) $settings->vat_number, $sellerCountry);
        $franchise = $this->isFranchise($sellerVat);

        $buyerCountry = $client->country?->iso_3166_2 ?? $sellerCountry;
        $rule = $this->processingRule($client, $buyerCountry);

        $lines = $this->lines($entity, $franchise, (bool) $client->is_tax_exempt);
        [$allowances, $charges] = $this->documentAllowancesAndCharges($entity, $lines['groups']);

        $groups = $this->closeGroups($lines['groups']);
        $totals = $this->totals($entity, $isCredit, $lines['sum'], $allowances, $charges, $groups);

        $invoice = [
            'number' => (string) $entity->number,
            'type_code' => $isCredit ? 381 : 380,
            'issue_date' => $this->date($entity->date),
            'currency_code' => $client->currency()->code ?? 'EUR',
            'process_control' => [
                'specification_identifier' => self::SPECIFICATION,
                'business_process_type' => $this->businessProcess($entity, $isCredit),
            ],
            'notes' => $this->notes($entity, $rule),
            'purchase_order_reference' => $this->text($entity->po_number),
            'preceding_invoice_references' => $isCredit ? $this->precedingInvoice($entity) : null,
            'seller' => [
                'name' => $this->required($this->text($settings->name), 'Le nom de l\'entreprise est vide.'),
                'postal_address' => $this->address($settings->address1, $settings->address2, $settings->city, $settings->postal_code, $settings->state, $sellerCountry),
                'legal_registration_identifier' => ['scheme' => '0002', 'value' => $sellerSiren],
                'identifiers' => $sellerSiret ? [['scheme' => '0009', 'value' => $sellerSiret]] : null,
                'electronic_address' => $this->electronicAddress(config('superpdp.seller_address') ?: $sellerSiren),
                'vat_identifier' => $sellerVat,
                // BR-S/E/Z/AE-02: without a VAT number the SIREN is the tax registration (BT-32).
                'tax_registration_identifier' => $sellerVat ? null : $sellerSiren,
                'contact' => [
                    'email_address' => $this->text($settings->email),
                    'phone_number' => $this->text($settings->phone),
                ],
            ],
            'buyer' => $this->buyer($client, $rule, $buyerCountry, (string) $settings->email),
            'lines' => $lines['lines'],
            'document_level_allowances' => $allowances ?: null,
            'charges' => $charges ?: null,
            'vat_break_down' => array_map(fn (array $g) => [
                'vat_category_code' => $g['category'],
                'vat_category_rate' => $this->money($g['rate']),
                'vat_category_taxable_amount' => $this->money($g['taxable']),
                'vat_category_tax_amount' => $this->money($g['tax']),
                'vat_exemption_reason' => $g['reason'],
                'vat_exemption_reason_code' => $g['reason_code'],
            ], array_values($groups)),
            'totals' => [
                'sum_invoice_lines_amount' => $this->money($lines['sum']),
                'sum_allowances_amount' => $allowances ? $this->money($totals['allowances']) : null,
                'sum_charges_amount' => $charges ? $this->money($totals['charges']) : null,
                'total_without_vat' => $this->money($totals['net']),
                'total_vat_amount' => ['value' => $this->money($totals['vat']), 'currency_code' => $client->currency()->code ?? 'EUR'],
                'total_with_vat' => $this->money($totals['gross']),
                'paid_amount' => $totals['paid'] != 0 ? $this->money($totals['paid']) : null,
                'rounding_amount' => $totals['rounding'] != 0 ? $this->money($totals['rounding']) : null,
                'amount_due_for_payment' => $this->money($totals['due']),
            ],
        ];

        if (! $isCredit && filled($entity->due_date)) {
            $invoice['payment_due_date'] = $this->date($entity->due_date);
        } elseif ($totals['due'] > 0) {
            // BR-CO-25: a positive amount due needs a due date or payment terms.
            $invoice['payment_terms'] = config('superpdp.payment_terms');
        }

        if (! $isCredit && filled(config('superpdp.iban'))) {
            $invoice['payment_instructions'] = [
                'payment_means_type_code' => '58',
                'credit_transfers' => [[
                    'payment_account_identifier' => ['scheme' => '', 'value' => strtoupper(preg_replace('/\s+/', '', config('superpdp.iban')))],
                    'payment_service_provider_identifier' => $this->text(config('superpdp.bic')),
                ]],
                'remittance_information' => (string) $entity->number,
            ];
        }

        $invoice = array_merge($invoice, $this->delivery($entity, $client, $buyerCountry));

        return [
            'invoice' => $this->clean($invoice),
            'processing_rule' => $rule,
            'vat_breakdown' => array_map(fn (array $g) => [
                'category' => $g['category'],
                'rate' => $this->money($g['rate']),
                'taxable' => $this->money($g['taxable']),
                'tax' => $this->money($g['tax']),
            ], array_values($groups)),
            'total_with_vat' => round($totals['gross'], 2),
            'warnings' => $this->warnings,
        ];
    }

    private function processingRule(Client $client, string $buyerCountry): string
    {
        if ($client->classification === 'individual') {
            return 'B2C';
        }

        return $buyerCountry === 'FR' ? 'B2B' : 'B2BInt';
    }

    private function buyer(Client $client, string $rule, string $country, string $sellerEmail): array
    {
        $name = $this->text($client->present()->name());
        $email = $this->text($client->contacts->sortByDesc('is_primary')->first()?->email);

        $buyer = [
            'name' => $name,
            'postal_address' => $this->address($client->address1, $client->address2, $client->city, $client->postal_code, $client->state, $country),
            'vat_identifier' => $this->vatNumber((string) $client->vat_number, $country),
            'contact' => ['email_address' => $email],
        ];

        if ($rule === 'B2B') {
            [$siren, $siret] = $this->siren((string) $client->id_number, "du client « {$name} » (champ N° d'identification). S'il s'agit d'un particulier, choisissez la classification « Particulier »");
            $buyer['legal_registration_identifier'] = ['scheme' => '0002', 'value' => $siren];
            $buyer['identifiers'] = $siret ? [['scheme' => '0009', 'value' => $siret]] : null;
            // The routing ID field holds a specific address (SIREN_SUFFIXE…) when the buyer gave one.
            $buyer['electronic_address'] = $this->electronicAddress($this->text($client->routing_id) ?: $siren);

            return $buyer;
        }

        if (filled($client->routing_id)) {
            $buyer['electronic_address'] = $this->electronicAddress($client->routing_id);
        } elseif ($email) {
            // BR-FR-12 wants BT-49 even when nothing is routed; an e-mail address is accepted.
            $buyer['electronic_address'] = ['scheme' => 'EM', 'value' => $email];
        } elseif (filled($sellerEmail)) {
            $buyer['electronic_address'] = ['scheme' => 'EM', 'value' => $sellerEmail];
            $this->warnings[] = "Le client « {$name} » n'a pas d'e-mail : l'adresse électronique de l'acheteur (BT-49) reprend celle de l'entreprise.";
        } else {
            throw new MappingException("Le client « {$name} » n'a ni e-mail ni routing ID, et l'entreprise n'a pas d'e-mail : impossible de remplir l'adresse électronique de l'acheteur (BT-49).");
        }

        return $buyer;
    }

    /**
     * @return array{lines: array, sum: float, groups: array<string, array>}
     */
    private function lines(Invoice|Credit $entity, bool $franchise, bool $clientExempt): array
    {
        $inclusive = (bool) $entity->uses_inclusive_taxes;
        $documentRates = array_values(array_filter([(float) $entity->tax_rate1, (float) $entity->tax_rate2, (float) $entity->tax_rate3]));

        $lines = [];
        $groups = [];
        $sum = 0.0;
        $n = 0;

        foreach ((array) $entity->line_items as $item) {
            $item = (object) $item;

            $cost = (float) ($item->cost ?? 0);
            $qty = (float) ($item->quantity ?? 0);
            $name = $this->text($item->product_key ?? '');
            $notes = $this->text($item->notes ?? '');

            if ($name === '' && $notes === '' && $cost == 0.0) {
                continue; // blank row
            }

            $n++;

            $rates = array_values(array_unique(array_merge(
                array_values(array_filter([(float) ($item->tax_rate1 ?? 0), (float) ($item->tax_rate2 ?? 0), (float) ($item->tax_rate3 ?? 0)])),
                $documentRates,
            )));

            if (count($rates) > 1) {
                throw new MappingException("Ligne {$n} : plusieurs taux de taxe cumulés (".implode(' % + ', $rates).' %), une facture électronique n\'accepte qu\'un taux de TVA par ligne.');
            }

            [$category, $rate, $reasonCode, $reason] = $this->vatCategory((float) ($rates[0] ?? 0), (string) ($item->tax_id ?? ''), $franchise, $clientExempt, $n);

            $lineTotal = (float) ($item->line_total ?? $cost * $qty);
            $net = round($inclusive ? $lineTotal / (1 + $rate / 100) : $lineTotal, 2);

            // Unit prices are VAT exclusive and never negative (BR-27/28): a
            // negative line carries a negative quantity instead.
            $unitCost = $inclusive ? $cost / (1 + $rate / 100) : $cost;
            $netPrice = $qty != 0.0 ? $net / $qty : $unitCost;

            if ($netPrice < 0 || ($qty == 0.0 && $net < 0)) {
                $qty = -$qty;
                $netPrice = -$netPrice;
                $unitCost = -$unitCost;
            }

            $price = ['item_net_price' => $this->decimal($netPrice)];

            if ($qty != 0.0 && abs($unitCost * $qty - $net) > 0.005 && $unitCost >= $netPrice) {
                $price = [
                    'item_gross_price' => $this->decimal($unitCost),
                    'item_price_discount' => $this->decimal($unitCost - (float) $this->decimal($netPrice)),
                    'item_net_price' => $this->decimal($netPrice),
                ];
            }

            $unit = (string) ($item->unit_code ?? '') ?: 'C62';

            if ($unit === 'C62' && (string) ($item->type_id ?? '') === '2') {
                $unit = 'HUR'; // task lines are hours
            }

            if ($name === '') {
                $name = $notes !== '' ? mb_strimwidth(explode("\n", $notes)[0], 0, 120, '…') : "Ligne {$n}";
            }

            $lines[] = [
                'identifier' => (string) $n,
                'invoiced_quantity' => $this->decimal($qty),
                'invoiced_quantity_code' => $unit,
                'net_amount' => $this->money($net),
                'item_information' => [
                    'name' => $name,
                    'description' => $notes !== '' && $notes !== $name ? $notes : null,
                ],
                'price_details' => $price,
                'vat_information' => [
                    'invoiced_item_vat_category_code' => $category,
                    'invoiced_item_vat_rate' => $this->money($rate),
                ],
            ];

            $key = $category.'|'.$this->money($rate);
            $groups[$key] ??= ['category' => $category, 'rate' => $rate, 'reason' => $reason, 'reason_code' => $reasonCode, 'net' => 0.0, 'allowances' => 0.0, 'charges' => 0.0];
            $groups[$key]['net'] += $net;
            $sum += $net;
        }

        if ($lines === []) {
            throw new MappingException('Le document n\'a aucune ligne.');
        }

        return ['lines' => $lines, 'sum' => round($sum, 2), 'groups' => $groups];
    }

    /** @return array{0: string, 1: float, 2: ?string, 3: ?string} category, rate, exemption code, exemption text */
    private function vatCategory(float $rate, string $taxId, bool $franchise, bool $clientExempt, int $n): array
    {
        if ($franchise) {
            if ($rate != 0.0) {
                throw new MappingException("Ligne {$n} : TVA à {$rate} % alors que l'entreprise est en franchise en base (SUPERPDP_VAT_FRANCHISE).");
            }

            return ['E', 0.0, 'VATEX-FR-FRANCHISE', config('superpdp.vat_franchise_text')];
        }

        if ($rate > 0) {
            return ['S', $rate, null, null];
        }

        // Product tax categories, see App\Models\Product::PRODUCT_TYPE_*.
        return match (true) {
            $taxId === '9' => ['AE', 0.0, 'VATEX-EU-AE', 'Autoliquidation'],
            $taxId === '8' => ['Z', 0.0, null, null],
            $taxId === '5', $clientExempt => ['E', 0.0, null, config('superpdp.vat_exempt_text')],
            default => throw new MappingException("Ligne {$n} : TVA à 0 % sans motif. Appliquez un taux, ou choisissez la catégorie de taxe du produit (exonéré, taux zéro, autoliquidation)."),
        };
    }

    /**
     * Invoice discount and custom surcharges, split across VAT groups in
     * proportion to their net amounts.
     *
     * @return array{0: array, 1: array}
     */
    private function documentAllowancesAndCharges(Invoice|Credit $entity, array &$groups): array
    {
        $allowances = [];
        $charges = [];
        $base = array_sum(array_column($groups, 'net'));

        $discount = (float) $entity->discount;

        if ($discount != 0.0) {
            $amount = $entity->is_amount_discount ? $discount : round($base * $discount / 100, 2);
            $this->split($groups, $amount, 'allowances', $allowances, 'Remise', $entity->is_amount_discount ? null : $discount);
        }

        $labels = (object) ($entity->company->custom_fields ?? []);

        foreach ([1, 2, 3, 4] as $i) {
            $amount = (float) $entity->{"custom_surcharge{$i}"};

            if ($amount == 0.0) {
                continue;
            }

            $label = $this->text($labels->{"surcharge{$i}"} ?? '') ?: 'Frais';

            // Invoice Ninja only taxes surcharges through document-level
            // taxes, which has no faithful EN16931 equivalent once VAT applies.
            if (array_filter(array_column($groups, 'rate'))) {
                throw new MappingException("Frais « {$label} » sur une facture avec TVA : non pris en charge, ajoutez-les comme une ligne de la facture.");
            }

            if ($amount > 0) {
                $this->split($groups, $amount, 'charges', $charges, $label);
            } else {
                $this->split($groups, -$amount, 'allowances', $allowances, $label);
            }
        }

        if (($allowances || $charges) && $entity->uses_inclusive_taxes) {
            throw new MappingException('Remise globale ou frais avec des taxes incluses : non pris en charge, utilisez des prix HT.');
        }

        return [$allowances, $charges];
    }

    private function split(array &$groups, float $amount, string $kind, array &$out, string $reason, ?float $percent = null): void
    {
        $base = array_sum(array_column($groups, 'net'));
        $keys = array_keys($groups);
        $left = round($amount, 2);

        foreach ($keys as $i => $key) {
            $part = $i === count($keys) - 1 || $base == 0.0 ? $left : round($amount * $groups[$key]['net'] / $base, 2);
            $left = round($left - $part, 2);
            $groups[$key][$kind] += $part;

            $out[] = [
                'amount' => $this->money($part),
                'base_amount' => $percent !== null ? $this->money($groups[$key]['net']) : null,
                'percent' => $percent !== null ? $this->money($percent) : null,
                'reason' => $reason,
                'reason_code' => $kind === 'allowances' && $reason === 'Remise' ? '95' : null,
                'vat_category_code' => $groups[$key]['category'],
                'vat_rate' => $this->money($groups[$key]['rate']),
                'vat_exemption_reason' => $groups[$key]['reason'],
                'vat_exemption_reason_code' => $groups[$key]['reason_code'],
            ];
        }
    }

    private function closeGroups(array $groups): array
    {
        foreach ($groups as &$g) {
            $g['taxable'] = round($g['net'] - $g['allowances'] + $g['charges'], 2);
            $g['tax'] = round($g['taxable'] * $g['rate'] / 100, 2);
        }

        return $groups;
    }

    private function totals(Invoice|Credit $entity, bool $isCredit, float $sum, array $allowances, array $charges, array $groups): array
    {
        $allowanceTotal = round(array_sum(array_map(fn ($a) => (float) $a['amount'], $allowances)), 2);
        $chargeTotal = round(array_sum(array_map(fn ($c) => (float) $c['amount'], $charges)), 2);
        $net = round($sum - $allowanceTotal + $chargeTotal, 2);
        $vat = round(array_sum(array_column($groups, 'tax')), 2);
        $gross = round($net + $vat, 2);

        // Invoice Ninja rounds taxes per line; EN16931 per VAT group. Small
        // differences go to the rounding amount (BT-114) so the amount due
        // matches the PDF.
        $rounding = round((float) $entity->amount - $gross, 2);

        if (abs($rounding) > 0.05) {
            throw new MappingException(sprintf('Total incohérent : %s dans Invoice Ninja, %s recalculé pour la facture électronique.', $this->money((float) $entity->amount), $this->money($gross)));
        }

        $paid = $isCredit ? 0.0 : round((float) $entity->paid_to_date, 2);

        if ($gross + $rounding <= 0 && ! $isCredit) {
            throw new MappingException('Facture à montant nul ou négatif : émettez un avoir à la place.');
        }

        return [
            'allowances' => $allowanceTotal,
            'charges' => $chargeTotal,
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
            'rounding' => $rounding,
            'paid' => $paid,
            'due' => round($gross + $rounding - $paid, 2),
        ];
    }

    private function businessProcess(Invoice|Credit $entity, bool $isCredit): string
    {
        $letter = in_array(config('superpdp.business_process'), ['B', 'S', 'M'], true) ? config('superpdp.business_process') : 'S';

        // "2" = invoice already paid when issued.
        $paid = ! $isCredit && $entity->status_id == Invoice::STATUS_PAID && (float) $entity->balance == 0.0;

        return $letter.($paid ? '2' : '1');
    }

    private function notes(Invoice|Credit $entity, string $rule): array
    {
        $notes = [];

        foreach ((array) config('superpdp.notes') as $code => $text) {
            if (filled($text)) {
                $notes[] = ['note' => $text, 'subject_code' => $code];
            }
        }

        if ($rule === 'B2C') {
            $notes[] = ['note' => 'B2C', 'subject_code' => 'BAR'];
        }

        if ($public = $this->text($entity->public_notes)) {
            $notes[] = ['note' => $public, 'subject_code' => 'AAI'];
        }

        return $notes;
    }

    private function precedingInvoice(Credit $credit): array
    {
        $invoice = $credit->invoice_id ? Invoice::withTrashed()->find($credit->invoice_id) : null;

        if (! $invoice || blank($invoice->number)) {
            throw new MappingException('Un avoir doit référencer la facture qu\'il corrige (BR-FR-CO-05) : créez-le depuis la facture (Actions → Rembourser / Annuler) ou liez-le à une facture.');
        }

        return [[
            'reference' => (string) $invoice->number,
            'issue_date' => $this->date($invoice->date),
        ]];
    }

    private function delivery(Invoice|Credit $entity, Client $client, string $buyerCountry): array
    {
        $out = [];
        $field = config('superpdp.delivery_date_field');

        if ($field && preg_match('/^(invoice|credit)([1-4])$/', $field, $m)) {
            $raw = (string) $entity->{"custom_value{$m[2]}"};
            $dates = $this->parseDates($raw);

            if (count($dates) === 1) {
                $out['delivery_information'] = ['delivery_date' => $dates[0]];
            } elseif (count($dates) > 1) {
                $out['invoicing_period'] = ['start_date' => min($dates), 'end_date' => max($dates)];
            } elseif (trim($raw) !== '') {
                $this->warnings[] = "Date de prestation illisible (« {$raw} ») : elle n'est pas reprise dans la facture électronique.";
            }
        }

        if (filled($client->shipping_address1) && $client->shipping_address1 !== $client->address1) {
            $out['deliver_to_address'] = $this->address(
                $client->shipping_address1,
                $client->shipping_address2,
                $client->shipping_city,
                $client->shipping_postal_code,
                $client->shipping_state,
                $client->shipping_country?->iso_3166_2 ?? $buyerCountry,
            );
        } elseif (! isset($out['delivery_information'])) {
            // CII must not have an empty ApplicableHeaderTradeDelivery.
            $out['deliver_to_address'] = ['country_code' => $buyerCountry];
        }

        return $out;
    }

    /** @return string[] Y-m-d dates found in a free text field */
    private function parseDates(string $raw): array
    {
        preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b|\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})\b/', $raw, $m, PREG_SET_ORDER);

        $dates = [];

        foreach ($m as $match) {
            [$y, $mo, $d] = ($match[1] ?? '') !== '' ? [$match[1], $match[2], $match[3]] : [$match[6], $match[5], $match[4]];

            if (checkdate((int) $mo, (int) $d, (int) $y)) {
                $dates[] = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        return $dates;
    }

    private function isFranchise(?string $sellerVat): bool
    {
        $setting = config('superpdp.vat_franchise');

        if ($setting === null || $setting === '') {
            return $sellerVat === null;
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array{0: string, 1: ?string} SIREN and, when a SIRET was given, the SIRET */
    private function siren(string $raw, string $where): array
    {
        $digits = preg_replace('/\D/', '', $raw);

        return match (strlen($digits)) {
            9 => [$digits, null],
            14 => [substr($digits, 0, 9), $digits],
            default => throw new MappingException("SIREN (9 chiffres) ou SIRET (14 chiffres) manquant ou invalide {$where}."),
        };
    }

    private function vatNumber(string $raw, string $country): ?string
    {
        $vat = strtoupper(preg_replace('/[\s.\-]/', '', $raw));

        if ($vat === '') {
            return null;
        }

        return ctype_alpha(substr($vat, 0, 2)) ? $vat : $country.$vat;
    }

    /** "0225:123456789", "EM:x@y" or a bare French address (scheme 0225). */
    private function electronicAddress(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^([A-Z0-9]{2,4}):(.+)$/i', $raw, $m)) {
            return ['scheme' => strtoupper($m[1]), 'value' => trim($m[2])];
        }

        return ['scheme' => '0225', 'value' => $raw];
    }

    private function address(?string $line1, ?string $line2, ?string $city, ?string $postCode, ?string $state, string $country): array
    {
        return [
            'address_line1' => $this->text($line1),
            'address_line2' => $this->text($line2),
            'city' => $this->text($city),
            'post_code' => $this->text($postCode),
            'country_subdivision' => $this->text($state),
            'country_code' => $country,
        ];
    }

    private function date(mixed $value): string
    {
        return \Carbon\Carbon::parse($value)->toDateString();
    }

    private function money(float $value): string
    {
        $value = round($value, 2);

        return number_format($value == 0.0 ? 0.0 : $value, 2, '.', '');
    }

    private function decimal(float $value, int $scale = 6): string
    {
        $s = rtrim(rtrim(number_format(round($value, $scale), $scale, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private function text(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', preg_replace("/\r\n?/", "\n", $value)));
    }

    private function required(string $value, string $message): string
    {
        return $value !== '' ? $value : throw new MappingException($message);
    }

    /** Drop null, '' and empty arrays, recursively. */
    private function clean(array $data): array
    {
        $list = array_is_list($data);

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = $this->clean($v);
            }

            if ($v === null || $v === '' || $v === []) {
                unset($data[$k]);
            } else {
                $data[$k] = $v;
            }
        }

        return $list ? array_values($data) : $data;
    }
}
