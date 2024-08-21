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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// M-PESA  Confirmation Callback 
Route::post('/mpesa/confirmation/callback',[MpesaCallbackController::class,'handlec2bCallback']);

// M-PESA Validation Callback
Route::post('/mpesa/validation/callback',[MpesaCallbackController::class,'hanclec2bvalidation']);

//Fetch M-PESA Records
Route::get('/mpesa/payments/c2b',[MpesaDataFetchController::class,'fetchC2bPayments']);

// Register M-PESA Callback
Route::post('/mpesa/callback/register', [MpesaCallbackController::class,'registerCallback']);

//Initiate an STK request
Route::post('/mpesa/stk/initiate', [mpesaStkController::class, 'initiateStkRequest']);

//Handle the callback data from M-PESA
Route::post('/mpesa/stk/callback', [mpesaStkController::class, 'handleStkCallback']);

//Fetch M-PESA STK payments from the database
Route::get('/mpesa/payments/stk', [MpesaDataFetchController::class, 'fetchStkPayments']);

