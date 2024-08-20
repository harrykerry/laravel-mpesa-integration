<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MpesaAuthService


{


    /**
     * Generate an access token from M-PESA API.
     *
     * @param string $url The URL to request the token from.
     * @param string $consumerKey Your M-PESA consumer key.
     * @param string $consumerSecret Your M-PESA consumer secret.
     * @return array The access token and expiry or an error message.
     */

    public function generateAccessToken(string $url, string $consumerKey, string $consumerSecret): string|array
    {

        try {

            $client = new Client();


            // Create the Basic Auth token using the consumer key and secret

            $authToken = base64_encode("{$consumerKey}:{$consumerSecret}");


            $headers = [
                'Authorization' => 'Basic ' . $authToken
            ];


            $response = $client->get($url, [
                'headers' => $headers,
            ]);


            $responseBody = json_decode($response->getBody(), true);

            Log::channel('mpesa')->info('Auth Token fetched');

            return [
                'access_token' => $responseBody['access_token'],
                'expires_in' => $responseBody['expires_in']
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error("Auth Error: " . $errorMessage);

            return ['error' => $errorMessage];
        }
    }
}
