<?php

use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\MpesaDataFetchController;
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

Route::post('/v1/mpesaConfirmation/callback',[MpesaCallbackController::class,'handlec2bCallback']);

Route::post('/v1/mpesaValidation/callback',[MpesaCallbackController::class,'hanclec2bvalidation']);

Route::get('/fetch-records',[MpesaDataFetchController::class,'fetchMpesaData']);

