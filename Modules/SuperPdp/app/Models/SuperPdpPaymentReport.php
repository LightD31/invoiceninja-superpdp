<?php

namespace Modules\SuperPdp\Models;

use Illuminate\Database\Eloquent\Model;

class SuperPdpPaymentReport extends Model
{
    protected $table = 'superpdp_payment_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'paid_on' => 'date',
    ];
}
