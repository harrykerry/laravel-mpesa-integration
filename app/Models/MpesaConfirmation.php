<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_type',
        'transaction_id',
        'transaction_time',
        'transaction_amount',
        'business_shortcode',
        'billref_no',
        'invoice_no',
        'org_balance',
        'thirdparty_transid',
        'mobile_number',
        'first_name',
        'middle_name',
        'last_name'
    ];
}
