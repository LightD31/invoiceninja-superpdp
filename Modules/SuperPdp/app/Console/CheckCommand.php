<?php

namespace Modules\SuperPdp\Console;

use App\Models\Client;
use App\Models\Country;
use Illuminate\Console\Command;
use Modules\SuperPdp\Exceptions\SuperPdpException;
use Modules\SuperPdp\Services\SuperPdpClient;
use Modules\SuperPdp\Support\Companies;
use Modules\SuperPdp\Support\State;

class CheckCommand extends Command
{
    protected $signature = 'superpdp:check';

    protected $description = 'Check the SUPER PDP configuration, credentials and client data';

    public function handle(SuperPdpClient $api): int
    {
        $ok = true;
        $company = Companies::target();
        $settings = $company?->settings;
        $siren = $settings ? substr(preg_replace('/\D/', '', (string) $settings->id_number), 0, 9) : '';
        $iban = (string) config('superpdp.iban');

        $this->info('Configuration');
        $this->table(['Setting', 'Value'], [
            ['enabled (automatic sending/sync)', $this->yes(config('superpdp.enabled'))],
            ['credentials', $api->configured() ? 'set' : 'missing'],
            ['company', $company ? $settings->name : 'none: set SUPERPDP_COMPANY_KEY'],
            ['start date', State::startDate()],
            ['auto send / delay', $this->yes(config('superpdp.auto_send')).' / '.config('superpdp.send_delay').' s'],
            ['validate before upload', $this->yes(config('superpdp.validate'))],
            ['send B2C', $this->yes(config('superpdp.send_b2c'))],
            ['report payments (fr:212)', $this->yes(config('superpdp.report_payments'))],
            ['import received invoices', $this->yes(config('superpdp.import_incoming'))],
            ['VAT franchise', blank(config('superpdp.vat_franchise')) ? 'auto ('.$this->yes(blank($settings?->vat_number)).')' : $this->yes(filter_var(config('superpdp.vat_franchise'), FILTER_VALIDATE_BOOLEAN))],
            ['business process (BT-23)', config('superpdp.business_process').'1'],
            ['seller address (BT-34)', config('superpdp.seller_address') ?: "0225:{$siren} (SIREN)"],
            ['IBAN', $iban ? '…'.substr(preg_replace('/\s+/', '', $iban), -4) : 'not set (no payment instructions)'],
            ['delivery date field', config('superpdp.delivery_date_field') ?: 'not set'],
        ]);

        if (! $company) {
            return self::FAILURE;
        }

        if (($settings->enable_e_invoice ?? false) && ($settings->merge_e_invoice_to_pdf ?? false)) {
            $this->warn('Invoice Ninja also embeds its own Factur-X XML in emailed PDFs (Settings → E-Invoice). Turn "merge into PDF" off so clients only get the SUPER PDP version.');
        }

        if (strlen($siren) !== 9) {
            $this->error('The company has no SIREN in its ID number.');
            $ok = false;
        }

        if ($api->configured()) {
            try {
                $me = $api->me(fresh: true);
                $this->info("SUPER PDP account: {$me['formal_name']} ({$me['env']}, {$me['number_scheme']} {$me['number']})");

                if ($me['env'] === 'production' && $me['number'] !== $siren) {
                    $this->error("The account's SIREN ({$me['number']}) differs from the company's ({$siren}): uploads will be refused.");
                    $ok = false;
                }

                if ($me['env'] === 'production') {
                    foreach ($api->directoryEntries($siren) as $entry) {
                        $this->line(sprintf('  directory: %s %s', $entry['identifier'], $entry['is_active'] ? 'active' : 'inactive'));
                    }
                } else {
                    $this->warn('Sandbox: set SUPERPDP_SELLER_ADDRESS to your sandbox address and the test client\'s routing ID to a sandbox buyer (315143296_…).');
                }
            } catch (SuperPdpException $e) {
                $this->error('SUPER PDP: '.$e->getMessage());
                $ok = false;
            }
        }

        $this->info('Clients');
        $france = Country::where('iso_3166_2', 'FR')->value('id');
        $problems = [];

        foreach (Client::where('company_id', $company->id)->where('is_deleted', false)->with('contacts')->get() as $client) {
            $digits = preg_replace('/\D/', '', (string) $client->id_number);

            if ($client->classification === 'individual') {
                if (! $client->contacts->pluck('email')->filter()->count() && blank($client->routing_id)) {
                    $problems[] = [$client->present()->name(), 'private, no e-mail: BT-49 will use the company e-mail'];
                }
            } elseif ($client->country_id == $france && ! in_array(strlen($digits), [9, 14], true)) {
                $problems[] = [$client->present()->name(), 'French business without SIREN/SIRET in ID number: its invoices will fail (or set classification to Individual)'];
            }
        }

        $problems ? $this->table(['Client', 'Problem'], $problems) : $this->line('  all clients look fine');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function yes(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
    }
}
