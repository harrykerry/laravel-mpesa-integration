<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaCallbackRegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Registers the URLs M-PESA calls when a customer pays the shortcode.
 *
 * C2B payments are not initiated here or anywhere else: the customer pays
 * Safaricom directly. This endpoint only tells Safaricom where to send the
 * validation and confirmation callbacks.
 */
class CallbackUrlController extends Controller
{
    public function __construct(protected readonly MpesaCallbackRegistrationService $registration) {}

    /**
     * Register the configured confirmation and validation URLs.
     *
     * Everything defaults to configuration; only the shortcode, the two URLs
     * and the response type may be overridden, and the URLs must be HTTPS
     * because M-PESA will not call anything else.
     */
    public function registerUrls(Request $request): JsonResponse
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

        $registration = $this->registration->registerUrls($validator->validated());

        return ApiResponse::success(
            data: $registration,
            message: trim('Callback URLs registered for shortcode '.$registration['shortcode'].'. '.($registration['mpesa_description'] ?? '')),
        );
    }
}
