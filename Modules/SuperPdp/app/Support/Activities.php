<?php

namespace Modules\SuperPdp\Support;

use App\Models\Activity;
use App\Models\Credit;
use App\Models\Invoice;

/**
 * Writes the built-in e-invoice activities (EINVOICE_SENT, _DELIVERY_SUCCESS,
 * _DELIVERY_FAILURE, _STATUS_UPDATED) so the history shows in the invoice's
 * activity tab, like Invoice Ninja's own Peppol sending.
 */
class Activities
{
    public static function write(Invoice|Credit $entity, int $type, string $notes): void
    {
        $activity = new Activity();
        $activity->user_id = $entity->user_id;
        $activity->client_id = $entity->client_id;
        $activity->company_id = $entity->company_id;
        $activity->account_id = $entity->company->account_id;
        $activity->activity_type_id = $type;
        $activity->invoice_id = $entity instanceof Invoice ? $entity->id : null;
        $activity->credit_id = $entity instanceof Credit ? $entity->id : null;
        $activity->notes = mb_strimwidth(str_replace('"', '', $notes), 0, 1000, '…');
        $activity->is_system = true;
        $activity->save();
    }

    public static function sent(Invoice|Credit $entity, string $notes): void
    {
        self::write($entity, Activity::EINVOICE_SENT, $notes);
    }

    public static function delivered(Invoice|Credit $entity, string $notes): void
    {
        self::write($entity, Activity::EINVOICE_DELIVERY_SUCCESS, $notes);
    }

    public static function failed(Invoice|Credit $entity, string $notes): void
    {
        self::write($entity, Activity::EINVOICE_DELIVERY_FAILURE, $notes);
    }

    public static function status(Invoice|Credit $entity, string $notes): void
    {
        self::write($entity, Activity::EINVOICE_STATUS_UPDATED, $notes);
    }
}
