# Laravel M-PESA (Safaricom Daraja)

A Laravel backend for the whole Safaricom Daraja surface, not just C2B callbacks:
collections, payouts, reversals, status queries, balances and QR codes.

Every callback Safaricom sends is written down, acknowledged immediately and processed
on a queue, so M-PESA never waits on your database and a payment is never lost to a slow
query or a failed write. Credentials never leave your environment file: a frontend asks
for a payment with a phone number and an amount, and nothing else.

- **Laravel 13**, **PHP 8.5**
- Plug it in as an API behind your own frontend, or use the services directly from Blade,
  Livewire, a job or a command in the same project
- 89 tests covering the flows, the security rules and every endpoint

## What it covers

| Product | What it does | Endpoint |
| --- | --- | --- |
| STK push | Prompt a customer to pay (Lipa na M-PESA Online) | `POST /api/v1/mpesa/stk/push` |
| STK query | Ask what became of a push | `POST /api/v1/mpesa/stk/query` |
| C2B register URLs | Tell M-PESA where to send C2B callbacks | `POST /api/v1/mpesa/c2b/register-urls` |
| C2B simulate | Sandbox only: pretend a customer paid | `POST /api/v1/mpesa/c2b/simulate` |
| Dynamic QR | A QR code for the customer to scan | `POST /api/v1/mpesa/qr-code` |
| B2C | Pay a customer | `POST /api/v1/mpesa/payouts/customer` |
| B2B | Pay another business | `POST /api/v1/mpesa/payouts/business` |
| Tax remittance | Pay the KRA against an iTax PRN | `POST /api/v1/mpesa/payouts/tax` |
| Reversal | Send a payment back | `POST /api/v1/mpesa/transaction/reversal` |
| Transaction status | Ask M-PESA about a receipt | `POST /api/v1/mpesa/transaction/status` |
| Account balance | The shortcode balance | `POST /api/v1/mpesa/account-balance` |
| Stored payments | Search, filter, sort, paginate | `GET /api/v1/payments/c2b`, `GET /api/v1/payments/stk` |

And the endpoints M-PESA calls, which are for Safaricom rather than for you:

| Callback | Endpoint |
| --- | --- |
| C2B validation (M-PESA waits for this one) | `POST /api/v1/callbacks/c2b/validation` |
| C2B confirmation | `POST /api/v1/callbacks/c2b/confirmation` |
| STK outcome | `POST /api/v1/callbacks/stk` |
| Result of a payout, reversal or query | `POST /api/v1/callbacks/result` |
| M-PESA's queue timed out | `POST /api/v1/callbacks/timeout` |

## Install

One script does the whole thing, and it is safe to run again:

```bash
./bin/setup              # locally, with composer and php
./bin/setup --docker     # or in Docker, nothing else to install
```

It installs dependencies, creates `.env` (keeping one that exists), generates the app key
only if there is none, migrates, and prints an API key for your frontend.

<details>
<summary>Or step by step</summary>

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# issue a key for your frontend to call this API with
php artisan mpesa:api-key --for=checkout

# callbacks are processed on the queue, so run a worker
php artisan queue:work --queue=mpesa
```

</details>

### With Docker

```bash
cp .env.example .env
docker compose up -d                        # app, queue worker, redis, mysql
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

docker compose --profile rabbitmq up -d     # only if you want RabbitMQ
```

The app is then on `localhost:8000`, and RabbitMQ's dashboard on `localhost:15672`
(guest / guest). `Dockerfile` serves the app with artisan and runs a worker beside it,
which is enough for local work; in production put PHP-FPM behind nginx and run the worker
under a supervisor.

Open the app in a browser for a guide to everything below, read from your own
configuration, in Safaricom's own colours. See [the landing page](#the-landing-page) if you
would rather not keep it.

Then tell Safaricom where to send C2B traffic, once per shortcode:

```bash
curl -X POST https://your-app.test/api/v1/mpesa/c2b/register-urls \
  -H "X-API-Key: your-key"
```

### Requirements

PHP 8.5, a database, and a queue broker. Redis is the default, RabbitMQ is installed and
configured, and the plain database queue works with no extra service at all; see
[Queues](#queues). `compose.yaml` runs all three locally.

## Configuration

Everything lives in `config/mpesa.php`, which reads your environment file. No `env()` call
exists anywhere else in the application, so `php artisan config:cache` is safe.

```dotenv
MPESA_ENV=sandbox                  # or production

MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=

MPESA_C2B_SHORTCODE=               # the paybill or till customers pay
MPESA_STK_SHORTCODE=               # the online checkout shortcode
MPESA_STK_PASSKEY=
MPESA_STK_TRANSACTION_TYPE=CustomerPayBillOnline

MPESA_INITIATOR_NAME=              # for payouts, status, balance, reversal
MPESA_SECURITY_CREDENTIAL=
MPESA_PAYOUT_SHORTCODE=

MPESA_CONFIRMATION_URL=https://your-app.test/api/v1/callbacks/c2b/confirmation
MPESA_VALIDATION_URL=https://your-app.test/api/v1/callbacks/c2b/validation
MPESA_STK_CALLBACK_URL=https://your-app.test/api/v1/callbacks/stk
MPESA_RESULT_URL=https://your-app.test/api/v1/callbacks/result
MPESA_QUEUE_TIMEOUT_URL=https://your-app.test/api/v1/callbacks/timeout
```

Callback URLs must be HTTPS and reachable without authentication: M-PESA sends no headers
of yours. `.env.example` lists the rest, grouped and commented.

## Calling the API

Every response shares one envelope:

```json
{
  "status": "success",
  "message": "Success. Request accepted for processing",
  "data": { "checkout_request_id": "ws_CO_191220191020363925", "merchant_request_id": "29115-34620561-1" }
}
```

```json
{
  "status": "error",
  "message": "The request is not valid.",
  "error_code": "validation.failed",
  "errors": { "msisdn": ["The mobile number must be a Kenyan number, for example 0712345678."] }
}
```

Safaricom's raw bodies are never passed through; they are logged, and the API answers in
its own shape with Safaricom's description as the message.

### Asking a customer to pay

```bash
curl -X POST https://your-app.test/api/v1/mpesa/stk/push \
  -H "X-API-Key: your-key" \
  -H "Idempotency-Key: checkout-9001" \
  -H "Content-Type: application/json" \
  -d '{"amount": 150, "msisdn": "0712345678"}'
```

That is the whole request. The shortcode, passkey, transaction type, account reference and
callback URL are deployment configuration, so a frontend never sees them and cannot choose
them. Anything else in the payload is ignored.

### Reading payments back

```bash
curl -G https://your-app.test/api/v1/payments/c2b \
  -H "X-API-Key: your-key" \
  --data-urlencode "search=INV-900" \
  --data-urlencode "from=2026-11-01" \
  --data-urlencode "min_amount=100" \
  --data-urlencode "sort=transaction_time" \
  --data-urlencode "per_page=25"
```

Both payment lists take `search` (receipt, reference, payer, name), `msisdn`, `shortcode`,
`transaction_id`, a `from`/`to` date window, `min_amount`/`max_amount`, `sort`, `direction`
and `per_page`. STK also takes `status=paid|pending`, `merchant_request_id` and
`checkout_request_id`. Every filter is optional, and an empty result is an empty page
rather than an error.

### Versioning

Routes live under `/api/v1` in `routes/api.php`. A breaking change goes in a new `v2` group
beside it, with its own controllers in `app/Http/Controllers/V2`, so existing callers keep
working until they move.

## Security

| Concern | How it is handled |
| --- | --- |
| Callbacks | Only Safaricom's published IPs (CIDR supported) and only the hosts you allowlist |
| Your endpoints | An API key in `X-API-Key` or as a bearer token, compared in constant time |
| Repeat requests | `Idempotency-Key` replays the first response; an identical payload with no key is caught for 60 seconds |
| Flooding | Rate limits per sending IP, and for pushes per payer as well as per caller |
| Outbound traffic | A host allowlist checked before any request leaves |
| Secrets in logs | Passwords and security credentials are redacted |

```bash
php artisan mpesa:api-key --for=checkout-frontend
```

Keep the key out of the browser: hold it in your own backend and call this API from there.
Give each caller its own key so one can be revoked on its own. Behind a load balancer set
`TRUSTED_PROXIES`, or every callback will look like it came from the proxy and the IP
allowlist cannot work. Sanctum is installed too, if you would rather issue per-user tokens.

## Callbacks and queues

A callback is stored in `mpesa_callbacks` exactly as it arrived, acknowledged to Safaricom,
and processed by a queued job. A resend lands on the same row instead of being processed
twice, and the raw payload stays for replay and audit. The job writes the payment and marks
the callback in one transaction.

C2B validation is the exception. M-PESA holds the payment until it answers, so it is decided
inline. Accept and the customer is deducted; reject and their money is left alone.

**This is where business rules go**, in `MpesaC2bValidationService::checks()`:

```php
public function checks(array $payload): ?C2bRejection
{
    // Return null to accept the payment, or a rejection to stop it.
    if (Invoice::where('reference', $payload['BillRefNumber'])->doesntExist()) {
        return C2bRejection::InvalidAccountNumber;
    }

    return null;
}
```

Amount bounds, a known shortcode and a bill reference pattern are already there, driven by
`config('mpesa.validation')`. The rejection codes are Safaricom's own, and the customer sees
them.

Payouts, reversals and status queries are answered twice: an acknowledgement now, the real
outcome on the result callback later. Each accepted request is stored in `mpesa_requests`,
and the result is matched back to it by conversation id. To act on an outcome, use
`MpesaResultService::handle()`, where the matching request is already updated.

Confirmed payments can be forwarded to another system over HTTP, on the queue, with retries
and an HMAC signature in `X-Mpesa-Signature`. Set `MPESA_FORWARD_URL`, or leave it unset and
nothing is sent.

### Queues

Jobs do not choose a broker themselves. They are dispatched with
`onConnection(config('mpesa.queue.connection'))` and `onQueue('mpesa')`, so the connection
is whatever you configure, and the queue name is `mpesa` unless you change it:

```dotenv
QUEUE_CONNECTION=redis       # the default
QUEUE_CONNECTION=rabbitmq    # driver installed, connection configured
QUEUE_CONNECTION=database    # no extra service, fine at low volume
```

Leave `MPESA_QUEUE_CONNECTION` blank and the M-PESA jobs follow `QUEUE_CONNECTION` with the
rest of the application. Set it to move only them, for example `MPESA_QUEUE_CONNECTION=rabbitmq`
while everything else stays on Redis.

The RabbitMQ *driver* ships installed (`vladimir-yuldashev/laravel-queue-rabbitmq`, which
registers the `rabbitmq` connector) and the connection is in `config/queue.php`. The broker
itself is a service you run:

```bash
docker compose up -d redis       # the default
docker compose up -d rabbitmq    # management UI on :15672, guest / guest
```

`compose.yaml` ships Redis, RabbitMQ and MySQL for local work; in production point the same
variables at your managed instances. Jobs run on the `mpesa` queue, are retried five times
with growing backoff, and a job that exhausts its retries marks the callback failed and logs
a critical line.

```bash
php artisan queue:work --queue=mpesa                        # follows QUEUE_CONNECTION
php artisan queue:work rabbitmq --queue=mpesa               # or name the connection
```

## Using it without the API

Nothing in the services touches a request, a session or a response, so the same code works
from a controller, a job, a command, Blade or Livewire:

```php
use App\Services\MpesaStkService;

class Checkout extends Component
{
    public function pay(MpesaStkService $stk)
    {
        $push = $stk->initiatePush(amount: 150, msisdn: '0712345678');

        $this->checkoutRequestId = $push['checkout_request_id'];
    }
}
```

`MpesaPayoutService`, `MpesaTransactionService`, `MpesaQrService`, `MpesaC2bService` and
`MpesaDataFetchService` work the same way.

## The landing page

The default Laravel welcome page is replaced with a Livewire page that documents the project,
reads which settings this deployment has in place, and can send a sandbox STK push. It is a
guide, not part of the integration. To remove it:

1. Delete `resources/views/components/⚡welcome.blade.php`, `resources/views/layouts/app.blade.php`
   and `tests/Feature/LandingPageTest.php`
2. Replace the route in `routes/web.php` with your own
3. Optionally `composer remove livewire/livewire`

Nothing else depends on any of it. The sandbox demo is off unless `MPESA_DEMO_ENABLED=true`
with sandbox credentials in place, so it cannot fire a real payment request by accident.

## Project structure

```
app/
  Console/Commands/GenerateApiKey.php    php artisan mpesa:api-key
  Enums/                                 callback types and statuses, C2B rejection codes
  Http/Controllers/V1/                   one controller per job, validation inline
  Http/Middleware/                       IP allowlist, host allowlist, API key, idempotency
  Jobs/                                  queued callback processing and outbound forwarding
  Models/                                MpesaConfirmation, MpesaStkPayment, MpesaCallback, MpesaRequest
  Services/                              MpesaClient and one service per M-PESA product
  Support/                               ApiResponse, Msisdn, HostGuard
config/mpesa.php                         every setting, grouped and commented
routes/api.php                           the v1 API
```

`MpesaClient` is the only way out to Daraja: it authenticates with a cached access token,
applies your timeouts, retries only what is safe to repeat, logs every request and response
with secrets redacted, and raises `MpesaException` when a call fails.

## Testing

```bash
php artisan test
```

The suite runs against SQLite in memory with every HTTP call faked, so no Daraja credentials
are needed. `Tests\Concerns\InteractsWithMpesa` gives a configured sandbox and helpers for
faking M-PESA and sending callbacks from a Safaricom address.

## Contact

Harold Kerry — happy to help with an integration or a Daraja go-live.

- Email: haroldkerry@gmail.com
- Phone: +254 743 417 198
- Web: [haroldkerry.co.ke](https://haroldkerry.co.ke) · [volcha.co.ke](https://volcha.co.ke)
- GitHub: [harrykerry](https://github.com/harrykerry)

## License

MIT.
