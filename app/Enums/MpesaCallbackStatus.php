<?php

namespace App\Enums;

/**
 * How far a received callback has got through processing.
 */
enum MpesaCallbackStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
