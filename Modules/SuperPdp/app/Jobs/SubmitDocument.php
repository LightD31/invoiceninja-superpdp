<?php

namespace Modules\SuperPdp\Jobs;

use App\Libraries\MultiDB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\SuperPdp\Models\SuperPdpDocument;
use Modules\SuperPdp\Services\Submitter;

class SubmitDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $documentId, public string $db) {}

    /** 1 min, 5 min, 15 min, 1 h, 3 h between attempts. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(Submitter $submitter): void
    {
        MultiDB::setDb($this->db);

        if ($doc = SuperPdpDocument::find($this->documentId)) {
            $submitter->submit($doc);
        }
    }

    public function failed(?\Throwable $e): void
    {
        MultiDB::setDb($this->db);

        if ($doc = SuperPdpDocument::find($this->documentId)) {
            app(Submitter::class)->giveUp($doc, 'abandon après plusieurs essais : '.($e?->getMessage() ?? 'erreur inconnue'));
        }
    }
}
