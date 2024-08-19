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
     * @return string|array The access token or an error message.
     */

    public function generateAccessToken(string $url, string $consumerKey, string $consumerSecret): string|array
    {

        try {

            $client = new Client();


            // Create the Basic Auth token using the consumer key and secret

            $authToken = base64_encode("{$consumerKey}:{$consumerSecret}");

            // Prepare the headers with Basic Authentication
            $headers = [
                'Authorization' => 'Basic ' . $authToken
            ];

            // Send the GET request to the M-PESA token endpoint
            $response = $client->get($url, [
                'headers' => $headers,
            ]);


            // Decode and return the access token

            $responseBody = json_decode($response->getBody(), true);

            Log::channel('mpesa')->info('Auth Token fetched');

            return $responseBody['access_token'];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error("Auth Error: " . $errorMessage);

            return ['auth_error' => $errorMessage];
        }
    }
}
