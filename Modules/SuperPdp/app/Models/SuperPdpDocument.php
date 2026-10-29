<?php

namespace Modules\SuperPdp\Models;

use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $company_id
 * @property string $entity_type invoice|credit
 * @property int $entity_id
 * @property string|null $number
 * @property string|null $processing_rule
 * @property int|null $superpdp_id
 * @property string $status
 * @property string|null $last_event_code
 * @property string|null $last_event_text
 * @property string|null $error
 * @property array|null $vat_breakdown
 * @property string|null $total_with_vat
 * @property int $attempts
 * @property \Carbon\Carbon|null $sent_at
 */
class SuperPdpDocument extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'superpdp_documents';

    protected $guarded = ['id'];

    protected $casts = [
        'vat_breakdown' => 'array',
        'sent_at' => 'datetime',
    ];

    public static function typeOf(Invoice|Credit $entity): string
    {
        return $entity instanceof Credit ? 'credit' : 'invoice';
    }

    public static function for(Invoice|Credit $entity): ?self
    {
        return self::where('entity_type', self::typeOf($entity))->where('entity_id', $entity->id)->first();
    }

    public function entity(): Invoice|Credit|null
    {
        $class = $this->entity_type === 'credit' ? Credit::class : Invoice::class;

        return $class::withTrashed()->find($this->entity_id);
    }

    /** True once SUPER PDP holds the document (whatever happened after). */
    public function isUploaded(): bool
    {
        return $this->superpdp_id !== null;
    }
}
