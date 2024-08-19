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
        $consumerSecret = $callbackUrlData['consumer_secrest'];
        $shortcode = $callbackUrlData['shortcode'];
        $authUrl = env('SAF_AUTH_URL');
        $registerUrl = env('SAF_C2B_URL');

        //The query params are already passed in the auth url. Pass it as it is.


        try {

            $authService = new MpesaAuthService;

            $response = $authService->generateAccessToken($authUrl, $consumerKey, $consumerSecret);

            if (isset($response['auth_error'])) {

                $errorMessage = $response['auth_error'];

                Log::channel('mpesa')->error("Failed to fetch access token: $errorMessage");

                return response()->json(['error' => $errorMessage], 500);
            }

            $accessToken = $response;
            $client = new Client();

            $response = $client->post($registerUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],

                'json' => [
                    'Shortcode' => $shortcode,
                    'ResponseType' => 'Completed', //Check daraja docs on when to use Cancelled here. Usually when your validation URL cannot be reached
                    'ConfirmationURL' => $confirmationUrl,
                    'ValidationURL' => $validationUrl
                ],
            ]);


            $responseBody = json_decode($response->getBody(), true);

            Log::channel('mpesa')->info('Callback URL Registration' . $responseBody);

            return $responseBody;
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::error('Error registering M-PESA callback URL: ' . $errorMessage);

            return ['error' => $errorMessage];
        }
    }
}
