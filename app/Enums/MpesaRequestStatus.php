<?php

namespace App\Enums;

/**
 * Where an asynchronous Daraja request stands.
 */
enum MpesaRequestStatus: string
{
    case Accepted = 'accepted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
