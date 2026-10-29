# SUPER PDP module for Invoice Ninja

A native Invoice Ninja v5 module (`Modules/SuperPdp`, nwidart/laravel-modules) that connects a self-hosted Invoice Ninja to [SUPER PDP](https://www.superpdp.tech), a French *plateforme agréée* (PA/PDP):

- **Send**: every invoice and credit note marked sent (or emailed, or paid straight from draft) is turned into a Factur-X and uploaded. B2B invoices are delivered to the buyer's platform; B2C invoices are sent for e-reporting only.
- **Track**: the French lifecycle statuses (fr:200 Déposée … fr:203 Mise à disposition, fr:210 Refusée, fr:213 Rejetée…) appear in the invoice's activity history.
- **Payments**: each payment sends the *Encaissée* status (fr:212), which feeds payment e-reporting.
- **Receive**: supplier invoices received on SUPER PDP are imported as expenses (vendor, amount, taxes, original Factur-X attached).

Tested with Invoice Ninja 5.13.50 (`invoiceninja/invoiceninja-debian`) and the SUPER PDP API 1.38.0.beta.

## How it works

```
Invoice marked sent ──► queued job (after SUPERPDP_SEND_DELAY)
   │  InvoiceMapper: Invoice Ninja → EN16931 JSON (XP Z12-012 rules)
   │  PdfRenderer:   the usual PDF design, without Invoice Ninja's own XML
   ▼
SUPER PDP /invoices/convert (JSON + PDF → Factur-X)
   ▼
SUPER PDP /validation_reports (EN16931 + BR-FR schematrons)   ─ invalid ─► activity "failed delivery"
   ▼
SUPER PDP POST /invoices ─► activity "E-Invoice sent", Factur-X kept as a private document
   ▼
superpdp:sync (every 5 min) ─► lifecycle events → activities; received invoices → expenses
```

Invoice Ninja saves the "sent" status quietly (no model events), so the module listens to `InvoiceWasUpdated` / `CreditWasMarkedSent`, which fire once the number and due date are set. A sweep in `superpdp:sync` catches anything sent through a path without events. Each document gets one row in `superpdp_documents` (unique key), and an upload never happens twice:

- uploads are locked per document;
- after a failed attempt, the retry first looks for an upload with the same `external_id` (`invoice/F2026-0001`, `credit/A2026-0001`).

Payment reports (fr:212) are claimed in `superpdp_payment_reports` before the call, so a payment is never declared twice either.

### Mapping

| EN16931 / French rule | Taken from |
|---|---|
| BT-1 number, BT-2 date, BT-9 due date, BT-13 PO | the invoice |
| BT-3 type | 380 invoice, 381 credit note |
| BT-23 *cadre de facturation* | `SUPERPDP_BUSINESS_PROCESS` + `1`, or `2` when already paid |
| BT-25/26 preceding invoice (credit notes, BR-FR-CO-05) | the credit's linked invoice |
| BT-30 / BT-47 SIREN (scheme 0002), BT-29/46 SIRET (0009) | company / client **ID number** (9 or 14 digits) |
| BT-34 seller address (0225) | `SUPERPDP_SELLER_ADDRESS`, else the SIREN |
| BT-49 buyer address | client **routing ID** (`0225:123456789_SUFFIX` or bare), else the SIREN (B2B); e-mail (`EM`) for B2C |
| BT-31 / BT-32 | VAT number; without one the SIREN goes in BT-32 (needed for category E) |
| VAT category | franchise → `E` + `VATEX-FR-FRANCHISE`; rate > 0 → `S`; product tax category exempt → `E`, zero-rated → `Z`, reverse charge → `AE` |
| BG-1 notes PMT, PMD, AAB (BR-FR-05) | `SUPERPDP_NOTE_*`; public notes as `AAI`; `BAR`=`B2C` for private clients |
| BT-72 delivery date / BG-14 period | the custom field in `SUPERPDP_DELIVERY_DATE_FIELD` (`2026-10-15`, `15/10/2026`, or a range) |
| BG-17 IBAN/BIC | `SUPERPDP_IBAN`, `SUPERPDP_BIC` |
| B2B / B2C / B2BInt | client classification *Individual* → B2C; French client → B2B; foreign → B2BInt |

Line discounts become gross price / price discount; the invoice discount becomes document-level allowances split per VAT group; negative lines get a negative quantity (BR-27). When Invoice Ninja's per-line rounding differs from EN16931's per-group rounding by a few cents, the difference goes to BT-114 so the amount due matches the PDF. A larger gap is an error.

## Install

The module is baked into a small image built on top of the official one (the official image has no Composer and its vendor folder is not a volume).

1. Put this repository next to the compose file, e.g. `/APPS/InvoiceNinja/superpdp`.
2. In `docker-compose.yml`, replace the `app` image:

   ```yaml
   app:
     image: invoiceninja-superpdp:5
     build:
       context: ./superpdp
       args:
         IN_IMAGE: invoiceninja/invoiceninja-debian:5
   ```

3. Add the settings from [`.env.example`](.env.example) to `.env`.
4. `docker compose up -d --build app`. The module's migration runs with Invoice Ninja's at boot.
5. Check: `docker compose exec -u www-data app php artisan superpdp:check`.

**Updating Invoice Ninja**: `docker compose build --pull app && docker compose up -d app`.

**Removing the module**: put the official image back. The four `superpdp_*` tables can stay or be dropped.

## Commands

```sh
php artisan superpdp:check                      # config, credentials, SIREN match, clients missing a SIREN
php artisan superpdp:send F2026-0001 --dry-run  # build + validate, write JSON and Factur-X to storage/app/superpdp/dry-run (no credentials needed)
php artisan superpdp:send F2026-0001            # upload now (never twice)
php artisan superpdp:send A2026-0001 --credit   # credit notes
php artisan superpdp:sync [--events|--incoming|--sweep] [--retry-failed]
php artisan superpdp:status
```

(Run them with `docker compose exec -u www-data app php artisan …`.)

## Before going live

- Run `superpdp:send <number> --dry-run` on a real invoice. It uses SUPER PDP's public converter and validator, so it needs no credentials and uploads nothing.
- Test in the **sandbox** with a sandbox Application. Set `SUPERPDP_SELLER_ADDRESS` to your sandbox address and a test client's routing ID to a sandbox buyer (`315143296_…`, see *Annuaire* in the SUPER PDP docs). In sandbox the SIREN ownership check is skipped.
- Every French business client needs its SIREN (or SIRET) in **ID number**. Private clients need the classification **Individual**.
- In Settings → E-Invoice, leave "merge e-invoice into PDF" off, so clients don't get a second, different XML by e-mail.
- Only one tool should import received invoices from the same SUPER PDP account. If another app (e.g. Invoicerr) also polls it, set `SUPERPDP_IMPORT_INCOMING=false` on one side.

## Where to see things

There is no screen: Invoice Ninja's admin UI is a compiled SPA that modules can't extend. Instead:

- the invoice's **Activity** tab shows the e-invoice entries: "E-Invoice … was sent" with the SUPER PDP number, then status updates, delivered or failed with the reason;
- the uploaded Factur-X is attached to the invoice as a private document, and a received one is attached to its expense;
- `superpdp:status` lists documents, payment reports and imports.

## Not handled

- **Payment changes**: refunds, and deleted or edited payments, don't send any correction. Fix them on the SUPER PDP side.
- **Cancelling a sent invoice**: French rules require a credit note instead. Create it from the invoice so it's linked.
- **Unsupported invoice shapes**: surcharges on invoices with VAT, several taxes on one line, and inclusive taxes combined with a global discount or surcharge are refused with an explicit message.
- **Received invoices**: no buyer statuses (fr:204 Prise en charge, fr:205 Approuvée, fr:210 Refusée…) are sent for them. Use the SUPER PDP account.
- **B2G (Chorus Pro), self-billing and payee or third parties** aren't covered.
- **Invoice Ninja importer bug**: its Factur-X import crashes on supplier invoices that have no seller contact. The module tolerates that warning, and wraps each import in a transaction so a failed import leaves no empty expense.
