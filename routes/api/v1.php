<?php

use App\Http\Controllers\V1\C2bCallbackController;
use App\Http\Controllers\V1\C2bRegistrationController;
use App\Http\Controllers\V1\PaymentController;
use App\Http\Controllers\V1\StkCallbackController;
use App\Http\Controllers\V1\StkPushController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 with the route name prefix "api.v1." in
| bootstrap/app.php. A breaking change to any of these contracts belongs in
| a new routes/api/v2.php rather than in this file.
|
*/

Route::prefix('mpesa')->name('mpesa.')->group(function (): void {

    // Called by us: ask M-PESA to send C2B traffic to this application.
    Route::post('c2b/register-urls', [C2bRegistrationController::class, 'store'])->name('c2b.register');

    // Called by M-PESA: no authentication headers are possible on these.
    Route::post('c2b/validation', [C2bCallbackController::class, 'validation'])->name('c2b.validation');
    Route::post('c2b/confirmation', [C2bCallbackController::class, 'confirmation'])->name('c2b.confirmation');
    Route::post('stk/callback', StkCallbackController::class)->name('stk.callback');

    // Called by our own frontend.
    Route::post('stk/push', [StkPushController::class, 'store'])->name('stk.push');
});

Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::get('c2b', [PaymentController::class, 'c2b'])->name('c2b');
    Route::get('stk', [PaymentController::class, 'stk'])->name('stk');
});
