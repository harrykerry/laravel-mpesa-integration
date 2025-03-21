<?php


namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;


class MpesaCallbackRegistrationService
{


    protected MpesaAuthService $mpesaAuthService;

    public function __construct(MpesaAuthService $mpesaAuthService)
    {
        $this->mpesaAuthService = $mpesaAuthService;
    }

    /**
     * Register the M-PESA callback URLs with M-PESA.
     *
     * @param array $callbackUrlData An associative array containing callback URL data:
     *                                - 'confirmation_url' (string)
     *                                - 'validation_url' (string)
     *                                - 'consumer_key' (string)
     *                                - 'consumer_secret' (string)
     *                                - 'shortcode' (int)
     *
     * @return array The response from the M-PESA API or an error message.
     */


    public function registerCallBackUrl(array $callbackUrlData): array
    {

        $confirmationUrl = $callbackUrlData['confirmation_url'];
        $validationUrl = $callbackUrlData['validation_url'];
        $consumerKey = $callbackUrlData['consumer_key'];
        $consumerSecret = $callbackUrlData['consumer_secret'];
        $shortcode = $callbackUrlData['shortcode'];
        $authUrl = env('SAF_AUTH_URL');
        $registerUrl = env('SAF_C2B_URL');

        try {
            $response = $this->mpesaAuthService->generateAccessToken($authUrl, $consumerKey, $consumerSecret);

            if ($response['error'] === 'error') {

                return $response;
            }

            $accessToken = $response['access_token'];

            $ch = curl_init();

            $postData = json_encode([
                'ShortCode' => $shortcode,
                'ResponseType' => 'Completed',
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl
            ]);

            curl_setopt($ch, CURLOPT_URL, $registerUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {

                $errorMessage = curl_error($ch);

                Log::channel('mpesa')->error("CALLBACK_REGISTRATION_ERROR: $errorMessage");

                curl_close($ch);

                return [
                    'status' => 'error',
                    'message' => $errorMessage
                ];
            }

            $responseBody = json_decode($response, true);

            curl_close($ch);

            Log::channel('mpesa')->info("CALLBACK_REGISTRATION:", $responseBody);

            return [
                'status' => 'success',
                'message' => $responseBody
            ];
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error("CALLBACK_REGISTRATION_ERROR: $errorMessage");

            return [
                'status' => 'error',
                'message' => $errorMessage
            ];
        }
    }
}
