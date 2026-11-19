<?php

use App\Http\Controllers\V1\C2bSimulationController;
use App\Http\Controllers\V1\Callbacks\C2bCallbackController;
use App\Http\Controllers\V1\Callbacks\ResultCallbackController;
use App\Http\Controllers\V1\Callbacks\StkCallbackController;
use App\Http\Controllers\V1\CallbackUrlController;
use App\Http\Controllers\V1\PaymentController;
use App\Http\Controllers\V1\PayoutController;
use App\Http\Controllers\V1\QrCodeController;
use App\Http\Controllers\V1\StkPushController;
use App\Http\Controllers\V1\StkStatusController;
use App\Http\Controllers\V1\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Loaded with the "api" prefix, so everything below is served under
| /api/v1/... A breaking change to any of these contracts goes in a new v2
| group beside this one, leaving v1 working for existing callers.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
    | Endpoints M-PESA calls. M-PESA sends no headers of ours, so these are
    | recognised by the host they arrive on and the address they come from.
    | Everything but C2B validation is acknowledged at once and processed on
    | the queue.
    */
    Route::prefix('callbacks')
        ->middleware(['known.hosts', 'mpesa.ips', 'throttle:mpesa-callbacks'])
        ->group(function (): void {
            Route::post('c2b/validation', [C2bCallbackController::class, 'validatePayment']);
            Route::post('c2b/confirmation', [C2bCallbackController::class, 'receiveConfirmation']);
            Route::post('stk', [StkCallbackController::class, 'receiveCallback']);

            // Where the asynchronous APIs post their outcome, and where
            // M-PESA's queue reports giving up on one.
            Route::post('result', [ResultCallbackController::class, 'receiveResult']);
            Route::post('timeout', [ResultCallbackController::class, 'receiveTimeout']);
        });

    /*
    | Endpoints our own frontend and operators call, behind an API key.
    */
    Route::prefix('mpesa')->middleware('api.key')->group(function (): void {
        Route::post('stk/push', [StkPushController::class, 'initiatePush'])
            ->middleware(['idempotent', 'throttle:mpesa-push']);

        Route::post('stk/query', [StkStatusController::class, 'checkStatus'])
            ->middleware('throttle:mpesa-reads');

        Route::post('c2b/register-urls', [CallbackUrlController::class, 'registerUrls'])
            ->middleware('throttle:mpesa-operations');

        // Sandbox only: pretend a customer paid, to exercise the callbacks.
        Route::post('c2b/simulate', [C2bSimulationController::class, 'simulate'])
            ->middleware('throttle:mpesa-operations');

        Route::post('qr-code', [QrCodeController::class, 'generate'])
            ->middleware('throttle:mpesa-operations');

        // Asking M-PESA about, or undoing, a transaction.
        Route::post('transaction/status', [TransactionController::class, 'checkStatus'])
            ->middleware('throttle:mpesa-reads');

        Route::post('account-balance', [TransactionController::class, 'checkBalance'])
            ->middleware('throttle:mpesa-operations');

        Route::post('transaction/reversal', [TransactionController::class, 'reverse'])
            ->middleware(['idempotent', 'throttle:mpesa-operations']);

        // Money out: these are idempotent, so a retry cannot pay twice.
        Route::prefix('payouts')->middleware(['idempotent', 'throttle:mpesa-operations'])->group(function (): void {
            Route::post('customer', [PayoutController::class, 'payCustomer']);
            Route::post('business', [PayoutController::class, 'payBusiness']);
            Route::post('tax', [PayoutController::class, 'remitTax']);
        });
    });

    Route::prefix('payments')->middleware(['api.key', 'throttle:mpesa-reads'])->group(function (): void {
        Route::get('c2b', [PaymentController::class, 'listC2bPayments']);
        Route::get('stk', [PaymentController::class, 'listStkPayments']);
    });
});
