<?php

use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\MpesaDataFetchController;
use App\Http\Controllers\mpesaStkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// M-PESA C2B Confirmation Callback
Route::post('/payments/c2b/confirmation/callback', [MpesaCallbackController::class, 'handleC2bCallback']);

// M-PESA C2B Validation Callback
Route::post('/payments/c2b/validation/callback', [MpesaCallbackController::class, 'handleC2bValidation']);

// Fetch M-PESA C2B Payments
Route::get('/mpesa/payments/c2b',[MpesaDataFetchController::class,'fetchC2bPayments']);

// Register M-PESA Callback
Route::post('/mpesa/callback/register', [MpesaCallbackController::class,'registerCallback']);

// Initiate M-PESA STK Request
Route::post('/mpesa/stk/initiate', [mpesaStkController::class, 'initiateStkRequest']);

// Handle the M-PESA STK Callback
Route::post('/mpesa/stk/callback', [mpesaStkController::class, 'handleStkCallback']);

// Fetch M-PESA STK Payments
Route::get('/mpesa/payments/stk', [MpesaDataFetchController::class, 'fetchStkPayments']);

