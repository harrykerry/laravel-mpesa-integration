<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaStkPayments extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_request_id',
        'checkout_request_id',
        'transaction_id',
        'transaction_date',
        'amount',
        'msisdn'
    ];
}
