<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daraja Environment
    |--------------------------------------------------------------------------
    |
    | Which Safaricom Daraja environment this application talks to. The value
    | selects one of the base URLs below, so switching from the sandbox to
    | production is a single environment variable change.
    |
    | Supported: "sandbox", "production"
    |
    */

    'environment' => env('MPESA_ENV', 'sandbox'),

    'base_urls' => [
        'sandbox' => env('MPESA_SANDBOX_URL', 'https://sandbox.safaricom.co.ke'),
        'production' => env('MPESA_PRODUCTION_URL', 'https://api.safaricom.co.ke'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | The consumer key and secret of your Daraja app. These are secrets: they
    | belong in the environment file of the deployment, and they are never
    | accepted from an incoming HTTP request.
    |
    */

    'credentials' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | C2B (Paybill / Buy Goods)
    |--------------------------------------------------------------------------
    |
    | The shortcode that receives customer payments, and the response type
    | M-PESA should use when your validation URL cannot be reached.
    |
    | Response types: "Completed" (accept the payment), "Cancelled" (reject it)
    |
    */

    'c2b' => [
        'shortcode' => env('MPESA_C2B_SHORTCODE'),
        'response_type' => env('MPESA_C2B_RESPONSE_TYPE', 'Completed'),
    ],

    /*
    |--------------------------------------------------------------------------
    | STK Push (Lipa na M-PESA Online)
    |--------------------------------------------------------------------------
    |
    | The online checkout shortcode and its passkey, plus the defaults used
    | when a push request does not override them. Use party_b when the
    | shortcode that is billed differs from the one that is credited.
    |
    | Transaction types: "CustomerPayBillOnline" (paybill),
    |                    "CustomerBuyGoodsOnline" (till)
    |
    */

    'stk' => [
        'shortcode' => env('MPESA_STK_SHORTCODE'),
        'passkey' => env('MPESA_STK_PASSKEY'),
        'party_b' => env('MPESA_STK_PARTY_B'),
        'transaction_type' => env('MPESA_STK_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        'account_reference' => env('MPESA_STK_ACCOUNT_REFERENCE', 'Payment'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Initiator (Status, Balance and Reversal APIs)
    |--------------------------------------------------------------------------
    |
    | The API operator Safaricom issued for this organisation, and the
    | security credential: the operator password encrypted with Safaricom's
    | public certificate for the environment you are calling.
    |
    */

    'initiator' => [
        'name' => env('MPESA_INITIATOR_NAME'),
        'security_credential' => env('MPESA_SECURITY_CREDENTIAL'),
        'identifier_type' => env('MPESA_IDENTIFIER_TYPE', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts (B2C, B2B and Tax Remittance)
    |--------------------------------------------------------------------------
    |
    | The shortcode money leaves when this organisation pays out, and the
    | defaults for each payout product. Command IDs are what Safaricom calls
    | the kind of payout being made.
    |
    | B2C command IDs:  "BusinessPayment", "SalaryPayment", "PromotionPayment"
    | B2B command IDs:  "BusinessPayBill", "BusinessBuyGoods",
    |                   "BusinessToBusinessTransfer", "MerchantToMerchantTransfer"
    |
    */

    'payouts' => [
        'shortcode' => env('MPESA_PAYOUT_SHORTCODE'),
        'b2c' => [
            'command_id' => env('MPESA_B2C_COMMAND_ID', 'BusinessPayment'),
        ],
        'b2b' => [
            'command_id' => env('MPESA_B2B_COMMAND_ID', 'BusinessPayBill'),
            'sender_identifier_type' => env('MPESA_B2B_SENDER_IDENTIFIER_TYPE', 4),
            'receiver_identifier_type' => env('MPESA_B2B_RECEIVER_IDENTIFIER_TYPE', 4),
        ],
        'tax' => [
            'command_id' => env('MPESA_TAX_COMMAND_ID', 'PayTaxToKRA'),
            'receiver_shortcode' => env('MPESA_TAX_RECEIVER_SHORTCODE', '572572'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daraja Endpoints
    |--------------------------------------------------------------------------
    |
    | Paths appended to the base URL of the active environment. They are
    | configurable because Safaricom versions each product separately; the
    | C2B register URL, for example, is also published as a v2 path.
    |
    */

    'endpoints' => [
        'oauth' => env('MPESA_OAUTH_PATH', 'oauth/v1/generate?grant_type=client_credentials'),
        'register_url' => env('MPESA_REGISTER_URL_PATH', 'mpesa/c2b/v1/registerurl'),
        'c2b_simulate' => env('MPESA_C2B_SIMULATE_PATH', 'mpesa/c2b/v1/simulate'),
        'stk_push' => env('MPESA_STK_PUSH_PATH', 'mpesa/stkpush/v1/processrequest'),
        'stk_query' => env('MPESA_STK_QUERY_PATH', 'mpesa/stkpushquery/v1/query'),
        'transaction_status' => env('MPESA_TRANSACTION_STATUS_PATH', 'mpesa/transactionstatus/v1/query'),
        'account_balance' => env('MPESA_ACCOUNT_BALANCE_PATH', 'mpesa/accountbalance/v1/query'),
        'reversal' => env('MPESA_REVERSAL_PATH', 'mpesa/reversal/v1/request'),
        'b2c' => env('MPESA_B2C_PATH', 'mpesa/b2c/v3/paymentrequest'),
        'b2b' => env('MPESA_B2B_PATH', 'mpesa/b2b/v1/paymentrequest'),
        'b2b_express_checkout' => env('MPESA_B2B_EXPRESS_PATH', 'v1/ussdpush/get-msisdn'),
        'tax_remittance' => env('MPESA_TAX_REMITTANCE_PATH', 'mpesa/b2b/v1/remittax'),
        'dynamic_qr' => env('MPESA_DYNAMIC_QR_PATH', 'mpesa/qrcode/v1/generate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback URLs
    |--------------------------------------------------------------------------
    |
    | The publicly reachable URLs this application hands to M-PESA. They must
    | be HTTPS and must not require authentication headers, since M-PESA
    | cannot send any. Leave them unset to fall back to the routes below.
    |
    */

    'callbacks' => [
        'confirmation_url' => env('MPESA_CONFIRMATION_URL'),
        'validation_url' => env('MPESA_VALIDATION_URL'),
        'stk_url' => env('MPESA_STK_CALLBACK_URL'),
        'result_url' => env('MPESA_RESULT_URL'),
        'timeout_url' => env('MPESA_QUEUE_TIMEOUT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP
    |--------------------------------------------------------------------------
    |
    | Timeouts and retry behaviour for calls to Daraja. Retries only apply to
    | connection errors and server-side failures, never to a request M-PESA
    | has already accepted.
    |
    */

    'http' => [
        'timeout' => (int) env('MPESA_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('MPESA_HTTP_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('MPESA_HTTP_RETRIES', 2),
        'retry_delay' => (int) env('MPESA_HTTP_RETRY_DELAY', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Token Cache
    |--------------------------------------------------------------------------
    |
    | Daraja tokens are valid for an hour and are rate limited, so they are
    | cached and reused. The leeway is subtracted from the lifetime M-PESA
    | reports so a token is never used in the second it expires.
    |
    */

    'token' => [
        'store' => env('MPESA_TOKEN_CACHE_STORE'),
        'key' => env('MPESA_TOKEN_CACHE_KEY', 'mpesa:access_token'),
        'leeway' => (int) env('MPESA_TOKEN_LEEWAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The channel every M-PESA request, response and callback is written to.
    |
    */

    'log_channel' => env('MPESA_LOG_CHANNEL', 'mpesa'),

];
