<?php

namespace Modules\SuperPdp\Console;

use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\SuperPdp\Exceptions\MappingException;
use Modules\SuperPdp\Exceptions\SuperPdpException;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Services\Submitter;
use Modules\SuperPdp\Services\SuperPdpClient;
use Modules\SuperPdp\Support\Companies;

class SendCommand extends Command
{
    protected $signature = 'superpdp:send
        {numbers* : Invoice numbers (credit numbers with --credit)}
        {--credit : The numbers are credit notes}
        {--dry-run : Build and validate the Factur-X without uploading it (no credentials needed)}';

    protected $description = 'Upload invoices/credits to SUPER PDP now, or check them with --dry-run';

    public function handle(Submitter $submitter, SuperPdpClient $api): int
    {
        $company = Companies::target();

        if (! $company) {
            $this->error('Set SUPERPDP_COMPANY_KEY: this instance has several companies.');

            return self::FAILURE;
        }

        if (! $this->option('dry-run') && ! $api->configured()) {
            $this->error('SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET are not set (use --dry-run to test without them).');

            return self::FAILURE;
        }

        $class = $this->option('credit') ? Credit::class : Invoice::class;
        $ok = true;

        foreach ($this->argument('numbers') as $number) {
            $entity = $class::where('company_id', $company->id)->where('number', $number)->where('is_deleted', false)->first();

            if (! $entity) {
                $this->error("{$number}: not found");
                $ok = false;

                continue;
            }

            $ok = ($this->option('dry-run') ? $this->dryRun($submitter, $entity) : $this->send($submitter, $entity)) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function dryRun(Submitter $submitter, Invoice|Credit $entity): bool
    {
        try {
            $prepared = $submitter->prepare($entity, validate: true);
        } catch (MappingException|SuperPdpException $e) {
            $this->error("{$entity->number}: ".$e->getMessage());

            return false;
        }

        $dir = 'superpdp/dry-run';
        $base = SuperPdpDocument::typeOf($entity).'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $entity->number);
        Storage::disk('local')->put("{$dir}/{$base}.json", json_encode($prepared['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Storage::disk('local')->put("{$dir}/{$base}_factur-x.pdf", $prepared['facturx']);

        $report = $prepared['report'];
        $this->info(sprintf('%s: valid Factur-X (%s, %s, %s), rule %s, total %s',
            $entity->number,
            $report['format'] ?? '?',
            $report['conformance_level'] ?? '?',
            $prepared['invoice']['process_control']['business_process_type'],
            $prepared['processing_rule'],
            $prepared['invoice']['totals']['total_with_vat'],
        ));

        foreach ($report['subreports'] ?? [] as $sub) {
            $this->line(sprintf('  %-70s %3d checks, %d failures', basename($sub['validator']), $sub['checks_count'], count($sub['failures'])));
        }

        foreach ($prepared['warnings'] as $warning) {
            $this->warn('  '.$warning);
        }

        $this->line('  Files: '.Storage::disk('local')->path("{$dir}/{$base}.json").' and _factur-x.pdf');

        return true;
    }

    private function send(Submitter $submitter, Invoice|Credit $entity): bool
    {
        $doc = SuperPdpDocument::for($entity) ?? SuperPdpDocument::create([
            'company_id' => $entity->company_id,
            'entity_type' => SuperPdpDocument::typeOf($entity),
            'entity_id' => $entity->id,
            'number' => $entity->number,
            'status' => SuperPdpDocument::QUEUED,
        ]);

        if ($doc->isUploaded()) {
            $this->warn("{$entity->number}: already on SUPER PDP (n° {$doc->superpdp_id}, {$doc->status}). Never uploaded twice.");

            return true;
        }

        try {
            $submitter->submit($doc);
        } catch (SuperPdpException $e) {
            $this->error("{$entity->number}: ".$e->getMessage().' (a queued retry may still follow)');

            return false;
        }

        $doc->refresh();

        if ($doc->isUploaded()) {
            $this->info("{$entity->number}: uploaded, SUPER PDP n° {$doc->superpdp_id} ({$doc->processing_rule}, {$doc->last_event_code})");

            return true;
        }

        $this->error("{$entity->number}: {$doc->status} — {$doc->error}");

        return false;
    }
}
