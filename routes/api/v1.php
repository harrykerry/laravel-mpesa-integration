<?php

use App\Http\Controllers\V1\Callbacks\C2bCallbackController;
use App\Http\Controllers\V1\Callbacks\StkCallbackController;
use App\Http\Controllers\V1\CallbackUrlController;
use App\Http\Controllers\V1\PaymentController;
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

/*
| Endpoints M-PESA calls. No authentication header is possible on these, so
| they are recognised by source instead. Everything but C2B validation is
| acknowledged immediately and processed on the queue.
*/
Route::prefix('callbacks')->name('callbacks.')->group(function (): void {
    Route::post('c2b/validation', [C2bCallbackController::class, 'validatePayment'])->name('c2b.validation');
    Route::post('c2b/confirmation', [C2bCallbackController::class, 'receiveConfirmation'])->name('c2b.confirmation');
    Route::post('stk', [StkCallbackController::class, 'receiveCallback'])->name('stk');
});

/*
| Endpoints our own frontend and operators call.
*/
Route::prefix('mpesa')->name('mpesa.')->group(function (): void {
    Route::post('stk/push', [StkPushController::class, 'initiatePush'])->name('stk.push');
    Route::post('c2b/register-urls', [CallbackUrlController::class, 'registerUrls'])->name('c2b.register');
});

Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::get('c2b', [PaymentController::class, 'listC2bPayments'])->name('c2b');
    Route::get('stk', [PaymentController::class, 'listStkPayments'])->name('stk');
});
