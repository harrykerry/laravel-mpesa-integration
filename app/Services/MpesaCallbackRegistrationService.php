<?php


namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;


class MpesaCallbackRegistrationService
{

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

            $authService = new MpesaAuthService;

            $response = $authService->generateAccessToken($authUrl, $consumerKey, $consumerSecret);

            if (isset($response['error'])) {
                $errorMessage = $response['error'];
                Log::channel('mpesa')->error("Failed to fetch access token: $errorMessage");
                return ['error' => $errorMessage];
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
                Log::error('Error registering M-PESA callback URL: ' . $errorMessage);
                curl_close($ch);
                return ['error' => $errorMessage];
            }

            $responseBody = json_decode($response, true);
            curl_close($ch);

            Log::channel('mpesa')->info('Callback URL Registration', $responseBody);

            return ['success' => $responseBody];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('Error registering M-PESA callback URL: ' . $errorMessage);
            return ['error' => $errorMessage];
        }
    }
}
