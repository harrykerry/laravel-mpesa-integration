<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaCallbackRegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Registers this application's C2B URLs with M-PESA.
 */
class C2bRegistrationController extends Controller
{
    public function __construct(protected readonly MpesaCallbackRegistrationService $registration) {}

    /**
     * Register the configured confirmation and validation URLs.
     *
     * Everything defaults to configuration; only the shortcode, the two URLs
     * and the response type may be overridden, and the URLs must be HTTPS
     * because M-PESA will not call anything else.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shortcode' => ['sometimes', 'numeric'],
            'confirmation_url' => ['sometimes', 'url:https'],
            'validation_url' => ['sometimes', 'url:https'],
            'response_type' => ['sometimes', 'string', 'in:Completed,Cancelled'],
        ], [
            'shortcode.numeric' => 'The shortcode must be numeric.',
            'confirmation_url.url' => 'The confirmation URL must be a valid HTTPS URL.',
            'validation_url.url' => 'The validation URL must be a valid HTTPS URL.',
            'response_type.in' => 'The response type must be either Completed or Cancelled.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        return ApiResponse::success(
            data: $this->registration->registerUrls($validator->validated()),
            message: 'Callback URLs registered',
        );
    }
}
