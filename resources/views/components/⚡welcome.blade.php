<?php

use App\Exceptions\MpesaException;
use App\Models\MpesaCallback;
use App\Models\MpesaConfirmation;
use App\Models\MpesaStkPayment;
use App\Services\MpesaStkService;
use App\Support\Msisdn;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The project's landing page: what this is, how to run it, how to change it,
 * and a sandbox demo that drives the very same services the API uses.
 */
new class extends Component
{
    /**
     * Which group of endpoints is on show.
     */
    public string $tab = 'collect';

    #[Validate('required|numeric|min:1|max:70000')]
    public $amount = 10;

    #[Validate('required|string')]
    public string $msisdn = '';

    /**
     * What came back from the demo push, if it has been run.
     *
     * @var array<string, mixed>|null
     */
    public ?array $demoResult = null;

    public ?string $demoError = null;

    /**
     * Whether the sandbox demo may run at all.
     *
     * It needs the sandbox, credentials, and MPESA_DEMO_ENABLED=true, so it
     * can never fire a real payment request by accident.
     */
    #[Computed]
    public function demoAvailable(): bool
    {
        return config('mpesa.demo_enabled')
            && config('mpesa.environment') === 'sandbox'
            && filled(config('mpesa.credentials.consumer_key'))
            && filled(config('mpesa.stk.passkey'));
    }

    /**
     * Which settings are in place, as yes or no. No value is ever shown.
     *
     * @return array<string, array{label: string, ready: bool, hint: string}>
     */
    #[Computed]
    public function checklist(): array
    {
        return [
            'credentials' => [
                'label' => 'Daraja app credentials',
                'ready' => filled(config('mpesa.credentials.consumer_key')) && filled(config('mpesa.credentials.consumer_secret')),
                'hint' => 'MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET',
            ],
            'c2b' => [
                'label' => 'C2B shortcode',
                'ready' => filled(config('mpesa.c2b.shortcode')),
                'hint' => 'MPESA_C2B_SHORTCODE',
            ],
            'stk' => [
                'label' => 'STK shortcode and passkey',
                'ready' => filled(config('mpesa.stk.shortcode')) && filled(config('mpesa.stk.passkey')),
                'hint' => 'MPESA_STK_SHORTCODE, MPESA_STK_PASSKEY',
            ],
            'callbacks' => [
                'label' => 'Callback URLs',
                'ready' => filled(config('mpesa.callbacks.confirmation_url')) && filled(config('mpesa.callbacks.stk_url')),
                'hint' => 'MPESA_CONFIRMATION_URL, MPESA_VALIDATION_URL, MPESA_STK_CALLBACK_URL',
            ],
            'initiator' => [
                'label' => 'Initiator for payouts and status',
                'ready' => filled(config('mpesa.initiator.name')) && filled(config('mpesa.initiator.security_credential')),
                'hint' => 'MPESA_INITIATOR_NAME, MPESA_SECURITY_CREDENTIAL',
            ],
            'api_keys' => [
                'label' => 'API keys for your frontend',
                'ready' => config('mpesa.security.api_keys') !== [],
                'hint' => 'php artisan mpesa:api-key',
            ],
        ];
    }

    /**
     * A few counts, so the page shows whether anything has come through.
     *
     * @return array<string, int>|null
     */
    #[Computed]
    public function activity(): ?array
    {
        if (!Schema::hasTable('mpesa_callbacks')) {
            return null;
        }

        return [
            'callbacks' => MpesaCallback::query()->count(),
            'pending' => MpesaCallback::query()->pending()->count(),
            'failed' => MpesaCallback::query()->failed()->count(),
            'c2b' => MpesaConfirmation::query()->count(),
            'stk_paid' => MpesaStkPayment::query()->paid()->count(),
            'stk_waiting' => MpesaStkPayment::query()->awaitingCallback()->count(),
        ];
    }

    /**
     * Run a sandbox STK push, straight through the service the API uses.
     */
    public function sendDemoPush(MpesaStkService $stk): void
    {
        $this->demoResult = null;
        $this->demoError = null;

        if (!$this->demoAvailable()) {
            $this->demoError = 'The demo is switched off. Set MPESA_DEMO_ENABLED=true with sandbox credentials in place.';

            return;
        }

        $this->validate();

        try {
            $this->demoResult = $stk->initiatePush($this->amount, Msisdn::normalize($this->msisdn));
        } catch (MpesaException $e) {
            $this->demoError = $e->getMessage();
        }
    }

    /**
     * The endpoints this application serves, grouped for the page.
     *
     * @return array<string, array{title: string, blurb: string, routes: list<array{method: string, path: string, note: string}>, example: string}>
     */
    public function groups(): array
    {
        return [
            'collect' => [
                'title' => 'Collect money',
                'blurb' => 'An STK push prompts a customer on their handset. C2B is money they send you unprompted, which only ever arrives as a callback.',
                'routes' => [
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/stk/push', 'note' => 'Prompt a customer to pay. Takes only msisdn and amount.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/stk/query', 'note' => 'Ask what became of a push.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/c2b/register-urls', 'note' => 'Tell M-PESA where to send C2B callbacks.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/c2b/simulate', 'note' => 'Sandbox only: pretend a customer paid.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/qr-code', 'note' => 'A QR code for the customer to scan.'],
                ],
                'example' => <<<'BASH'
curl -X POST https://your-app.test/api/v1/mpesa/stk/push \
  -H "X-API-Key: $MPESA_API_KEY" \
  -H "Idempotency-Key: checkout-9001" \
  -H "Content-Type: application/json" \
  -d '{"amount": 150, "msisdn": "0712345678"}'
BASH,
            ],
            'payouts' => [
                'title' => 'Pay money out',
                'blurb' => 'B2C pays a customer, B2B pays another business, and tax remittance pays the KRA. Each answers twice: an acknowledgement now, the outcome on the result callback.',
                'routes' => [
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/payouts/customer', 'note' => 'B2C. Business, salary or promotion payment.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/payouts/business', 'note' => 'B2B. Paybill, buy goods or a transfer.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/payouts/tax', 'note' => 'Tax remittance against an iTax PRN.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/transaction/reversal', 'note' => 'Send a payment back.'],
                ],
                'example' => <<<'BASH'
curl -X POST https://your-app.test/api/v1/mpesa/payouts/customer \
  -H "X-API-Key: $MPESA_API_KEY" \
  -H "Idempotency-Key: refund-4412" \
  -H "Content-Type: application/json" \
  -d '{"amount": 500, "msisdn": "0712345678", "remarks": "Refund"}'
BASH,
            ],
            'read' => [
                'title' => 'Read what happened',
                'blurb' => 'Every stored payment is searchable, filterable, sortable and paginated. No filter is required, and an empty result is an empty page.',
                'routes' => [
                    ['method' => 'GET', 'path' => '/api/v1/payments/c2b', 'note' => 'Stored C2B payments.'],
                    ['method' => 'GET', 'path' => '/api/v1/payments/stk', 'note' => 'STK pushes, paid or pending.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/transaction/status', 'note' => 'Ask M-PESA about a receipt.'],
                    ['method' => 'POST', 'path' => '/api/v1/mpesa/account-balance', 'note' => 'The shortcode balance.'],
                ],
                'example' => <<<'BASH'
curl -G https://your-app.test/api/v1/payments/c2b \
  -H "X-API-Key: $MPESA_API_KEY" \
  --data-urlencode "search=INV-900" \
  --data-urlencode "from=2026-11-01" \
  --data-urlencode "min_amount=100" \
  --data-urlencode "sort=transaction_time" \
  --data-urlencode "per_page=25"
BASH,
            ],
            'callbacks' => [
                'title' => 'What M-PESA calls',
                'blurb' => 'These are for Safaricom, not for you. They accept only Safaricom addresses, are acknowledged at once, and are processed on the queue. Validation is the exception: M-PESA waits for it, so it answers inline.',
                'routes' => [
                    ['method' => 'POST', 'path' => '/api/v1/callbacks/c2b/validation', 'note' => 'Accept or reject before the customer is deducted.'],
                    ['method' => 'POST', 'path' => '/api/v1/callbacks/c2b/confirmation', 'note' => 'A completed C2B payment.'],
                    ['method' => 'POST', 'path' => '/api/v1/callbacks/stk', 'note' => 'The outcome of a push.'],
                    ['method' => 'POST', 'path' => '/api/v1/callbacks/result', 'note' => 'The outcome of a payout, status or reversal.'],
                    ['method' => 'POST', 'path' => '/api/v1/callbacks/timeout', 'note' => "When M-PESA's queue gives up."],
                ],
                'example' => <<<'PHP'
// app/Services/MpesaC2bValidationService.php
public function checks(array $payload): ?C2bRejection
{
    // Return null to accept the payment, or a rejection to stop it:
    // the customer is not deducted and no confirmation is sent.
    if (Invoice::where('reference', $payload['BillRefNumber'])->doesntExist()) {
        return C2bRejection::InvalidAccountNumber;
    }

    return null;
}
PHP,
            ],
        ];
    }
}; ?>

<div x-data="{ section: 'overview' }" class="mx-auto max-w-6xl px-6 py-10 lg:flex lg:gap-12 lg:py-16">

    {{-- Left-hand section nav. Sticky on a wide screen, stacked above the
         content on a narrow one, and it tracks the section being read. --}}
    <aside class="lg:w-56 lg:shrink-0">
        <div class="lg:sticky lg:top-10">
            <p class="text-xs uppercase tracking-wider text-stone-400">{{ config('app.name') }}</p>
            <p class="mt-1 font-semibold text-stone-800">M-PESA for Laravel</p>

            <nav class="mt-6 flex flex-wrap gap-x-4 gap-y-1 border-b border-stone-300/70 pb-4 text-sm lg:flex-col lg:gap-0 lg:border-b-0 lg:border-l lg:border-stone-300/70 lg:pb-0">
                @foreach ([
                    'overview' => 'Overview',
                    'flow' => 'How a payment moves',
                    'install' => 'Install',
                    'configured' => 'Your configuration',
                    'endpoints' => 'Endpoints',
                    'demo' => 'Sandbox demo',
                    'modify' => 'Changing it',
                    'going-live' => 'Before going live',
                    'contact' => 'Contact',
                ] as $anchor => $label)
                    <a href="#{{ $anchor }}"
                       @click="section = '{{ $anchor }}'"
                       :class="section === '{{ $anchor }}'
                           ? 'text-stone-800 font-medium lg:border-emerald-600'
                           : 'text-stone-500 hover:text-stone-800 lg:border-transparent'"
                       class="py-1 lg:-ml-px lg:border-l-2 lg:pl-4">{{ $label }}</a>
                @endforeach
            </nav>

            <dl class="mt-6 hidden space-y-1 text-xs text-stone-500 lg:block">
                <div class="flex justify-between gap-2"><dt>Laravel</dt><dd class="text-stone-700">{{ app()->version() }}</dd></div>
                <div class="flex justify-between gap-2"><dt>PHP</dt><dd class="text-stone-700">{{ PHP_VERSION }}</dd></div>
                <div class="flex justify-between gap-2"><dt>Daraja</dt><dd class="text-stone-700">{{ config('mpesa.environment') }}</dd></div>
                <div class="flex justify-between gap-2"><dt>Queue</dt><dd class="text-stone-700">{{ config('mpesa.queue.connection') ?: config('queue.default') }}</dd></div>
            </dl>
        </div>
    </aside>

    <main class="min-w-0 flex-1">

        <section id="overview" class="scroll-mt-10 border-b border-stone-300/70 pb-10">
            <h1 class="text-3xl font-semibold tracking-tight text-stone-800 sm:text-4xl">
                Safaricom M-PESA for Laravel
            </h1>
            <p class="mt-4 text-stone-600">
                A backend for the whole Daraja surface: STK push and C2B collections, B2C and B2B payouts, tax
                remittance, reversals, transaction status, account balance and QR codes. Callbacks are written
                down, acknowledged at once and processed on a queue, so Safaricom never waits on your database.
            </p>
            <p class="mt-4 text-stone-600">
                A frontend asks for a payment with a phone number and an amount, and nothing else. Shortcodes,
                passkeys, credentials and callback URLs stay in this application's environment file.
            </p>
        </section>
        {{-- How a payment moves --}}
        <section id="flow" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">How a payment moves</h2>
        <ol class="mt-5 space-y-5">
            @foreach ([
                ['Money starts moving', 'Either your frontend asks for an STK push, or a customer pays your shortcode on their own. Only the push is something you initiate; C2B arrives unannounced.'],
                ['Safaricom calls back', 'The payload is stored exactly as it arrived, acknowledged immediately, and handed to a queued job. A resend lands on the same row rather than being processed twice.'],
                ['Your system is told', 'The job writes the payment in one transaction. If you have configured a forwarding URL, the payment is delivered there on the queue, signed and retried.'],
            ] as $index => [$title, $copy])
                <li class="flex gap-4">
                    <span class="w-5 shrink-0 pt-0.5 text-sm text-stone-400">{{ $index + 1 }}.</span>
                    <div>
                        <h3 class="font-medium text-stone-800">{{ $title }}</h3>
                        <p class="mt-1 text-stone-600">{{ $copy }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="mt-5 text-stone-600">
            C2B validation is the one exception. M-PESA holds the payment until it answers, so it is decided
            inline: accept and the customer is deducted, reject and their money is left alone.
        </p>
    </section>

    {{-- Install --}}
    <section id="install" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Install</h2>
        <pre class="mt-5 overflow-x-auto rounded-md p-4 text-[13px] leading-relaxed text-stone-100"><code>composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# fill in the Daraja values in .env, then issue a key for your frontend
php artisan mpesa:api-key --for=checkout

# callbacks are processed on the queue, so run a worker
php artisan queue:work --queue=mpesa

# finally, tell Safaricom where to send C2B callbacks
curl -X POST http://localhost:8000/api/v1/mpesa/c2b/register-urls \
  -H "X-API-Key: your-key"</code></pre>

    </section>

        <section id="configured" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Your configuration</h2>
        <p class="mt-1 text-sm text-stone-500">Read live from your environment. Values are never shown, only whether they are set.</p>
        <ul class="mt-4 divide-y divide-stone-300/70 border-y border-stone-300/70">
            @foreach ($this->checklist() as $item)
                <li class="flex items-baseline justify-between gap-4 py-2.5">
                    <span>
                        <span class="text-stone-800">{{ $item['label'] }}</span>
                        <code class="ml-2 text-xs text-stone-500">{{ $item['hint'] }}</code>
                    </span>
                    <span class="shrink-0 text-sm {{ $item['ready'] ? 'text-emerald-700' : 'text-stone-400' }}">
                        {{ $item['ready'] ? 'set' : 'not set' }}
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Endpoints --}}
    <section id="endpoints" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Endpoints</h2>
        <p class="mt-2 text-stone-600">
            Everything is served under <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">/api/v1</code>.
            A breaking change gets a v2 group in <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">routes/api.php</code>; v1 keeps working.
        </p>

        <div class="mt-6 flex flex-wrap gap-x-5 gap-y-2 border-b border-stone-300/70 pb-3 text-sm">
            @foreach ($this->groups() as $key => $group)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="{{ $tab === $key ? 'font-semibold text-stone-800 underline decoration-emerald-600 decoration-2' : 'text-stone-500 hover:text-stone-800' }}">
                    {{ $group['title'] }}
                </button>
            @endforeach
        </div>

        @php($group = $this->groups()[$tab])
        <div wire:key="group-{{ $tab }}">
            <p class="mt-5 text-stone-600">{{ $group['blurb'] }}</p>

            <ul class="mt-5 divide-y divide-stone-300/70 border-y border-stone-300/70">
                @foreach ($group['routes'] as $route)
                    <li class="py-3">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="w-12 shrink-0 text-xs font-medium text-stone-500">{{ $route['method'] }}</span>
                            <code class="text-sm text-stone-800">{{ $route['path'] }}</code>
                        </div>
                        <p class="mt-1 pl-14 text-sm text-stone-600">{{ $route['note'] }}</p>
                    </li>
                @endforeach
            </ul>

            <pre class="mt-6 overflow-x-auto rounded-md p-4 text-[13px] leading-relaxed text-stone-100"><code>{{ $group['example'] }}</code></pre>
        </div>
    </section>

    {{-- Demo --}}
    <section id="demo" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Sandbox demo</h2>
        <p class="mt-2 text-stone-600">
            This form calls <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">MpesaStkService::initiatePush()</code>
            directly, the same service the API endpoint uses. That is the point: nothing in the services needs an
            HTTP request, so they work just as well from Blade, Livewire, a job or a command.
        </p>

        @if ($this->demoAvailable())
            <form wire:submit="sendDemoPush" class="mt-6 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="block text-sm text-stone-600">Amount (KES)</span>
                    <input type="number" wire:model="amount" min="1"
                        class="mt-1 w-32 rounded border border-stone-400/60 bg-[#f7f5f1] px-3 py-2 outline-none focus:border-emerald-600">
                </label>
                <label class="block">
                    <span class="block text-sm text-stone-600">Mobile number</span>
                    <input type="text" wire:model="msisdn" placeholder="0712345678"
                        class="mt-1 w-44 rounded border border-stone-400/60 bg-[#f7f5f1] px-3 py-2 outline-none focus:border-emerald-600">
                </label>
                <button type="submit" wire:loading.attr="disabled"
                    class="rounded bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="sendDemoPush">Send the prompt</span>
                    <span wire:loading wire:target="sendDemoPush">Asking M-PESA…</span>
                </button>
            </form>
            @error('amount') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('msisdn') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        @else
            <p class="mt-6 border-l-2 border-amber-500 bg-amber-100/50 px-4 py-3 text-sm text-stone-700">
                The demo is off. It needs <code>MPESA_DEMO_ENABLED=true</code>, the sandbox environment and sandbox
                credentials, so it cannot fire a real payment request by accident.
            </p>
        @endif

        @if ($demoError)
            <p class="mt-4 border-l-2 border-red-600 bg-red-100/40 px-4 py-3 text-sm text-stone-700">{{ $demoError }}</p>
        @endif

        @if ($demoResult)
            <div class="mt-4 border-l-2 border-emerald-600 bg-emerald-100/40 px-4 py-3 text-sm text-stone-700">
                <p class="font-medium text-stone-800">{{ $demoResult['customer_message'] }}</p>
                <p class="mt-1"><code>checkout_request_id</code>: {{ $demoResult['checkout_request_id'] }}</p>
                <p><code>merchant_request_id</code>: {{ $demoResult['merchant_request_id'] }}</p>
            </div>
        @endif

        <h3 class="mt-8 font-medium text-stone-800">What has come through</h3>
        @if ($this->activity())
            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
                @foreach ([
                    'Callbacks received' => $this->activity()['callbacks'],
                    'Queued' => $this->activity()['pending'],
                    'Failed' => $this->activity()['failed'],
                    'C2B payments' => $this->activity()['c2b'],
                    'Pushes paid' => $this->activity()['stk_paid'],
                    'Pushes waiting' => $this->activity()['stk_waiting'],
                ] as $label => $count)
                    <div class="flex items-baseline justify-between gap-3 border-b border-stone-300/70 py-1.5">
                        <dt class="text-stone-600">{{ $label }}</dt>
                        <dd class="font-medium text-stone-800">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            <button type="button" wire:click="$refresh" class="mt-3 text-sm text-emerald-700 underline hover:text-emerald-800">Refresh</button>
        @else
            <p class="mt-2 text-stone-600">Run <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">php artisan migrate</code> to create the tables.</p>
        @endif
    </section>

    {{-- Changing it --}}
    <section id="modify" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Changing it</h2>
        <dl class="mt-5 space-y-5">
            @foreach ([
                ['Reject a payment before the customer is deducted', 'MpesaC2bValidationService::checks()', 'Return a C2bRejection and M-PESA stops the payment; return null and it goes through. Amount bounds, shortcode and bill reference rules are already there, driven by config.'],
                ['Act on a payout or reversal outcome', 'MpesaResultService::handle()', 'Every asynchronous API posts its outcome here. The matching mpesa_requests row is already updated with the result code, so you get the request and its answer together.'],
                ['Tell another system about a payment', 'config/mpesa.php, forwarding', 'Set a URL and confirmed payments are delivered on the queue, signed with an HMAC and retried. Leave it unset and nothing is sent.'],
                ['Switch Redis for RabbitMQ', '.env, QUEUE_CONNECTION', 'Both connections ship configured. Set QUEUE_CONNECTION=rabbitmq, or MPESA_QUEUE_CONNECTION=rabbitmq to move only the M-PESA jobs.'],
                ['Use it behind your own Blade or Livewire', 'App\Services', 'Inject MpesaStkService, MpesaPayoutService, MpesaTransactionService or MpesaQrService anywhere. No service touches a request, a session or a response.'],
                ['Add a breaking change safely', 'routes/api.php', 'Add a v2 group beside v1 with its own controllers; existing callers stay on v1 until they move.'],
            ] as [$title, $where, $copy])
                <div>
                    <dt class="font-medium text-stone-800">{{ $title }}</dt>
                    <dd class="mt-1 text-stone-600">
                        <code class="text-sm text-emerald-800">{{ $where }}</code>
                        <span class="mt-1 block">{{ $copy }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Going live --}}
    <section id="going-live" class="scroll-mt-10 border-b border-stone-300/70 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Before you go live</h2>
        <ul class="mt-5 space-y-2.5 text-stone-600">
            <li>Keep API keys out of the browser. Hold them in your own backend and call this API from there.</li>
            <li>Give every caller its own key with <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">php artisan mpesa:api-key</code>, so one can be revoked on its own.</li>
            <li>Send an <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">Idempotency-Key</code> per checkout attempt, so a retry cannot prompt a customer twice.</li>
            <li>Set <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">TRUSTED_PROXIES</code> behind a load balancer, or every callback will look like it came from the proxy.</li>
            <li>Set <code class="rounded bg-stone-300/50 px-1.5 py-0.5 text-sm">MPESA_ALLOWED_HOSTS</code> to the domain you registered with Safaricom.</li>
            <li>Callback URLs must be HTTPS and reachable without authentication: M-PESA sends no headers of yours.</li>
        </ul>
    </section>

    {{-- Contact --}}
    <section id="contact" class="scroll-mt-10 py-10">
        <h2 class="text-xl font-semibold text-stone-800">Contact</h2>
        <p class="mt-2 text-stone-600">Built and maintained by Harold Kerry. Happy to help with an integration or a Daraja go-live.</p>
        <dl class="mt-5 grid gap-2 text-sm sm:grid-cols-2">
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-stone-500">Email</dt>
                <dd><a href="mailto:haroldkerry@gmail.com" class="text-emerald-700 underline hover:text-emerald-800">haroldkerry@gmail.com</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-stone-500">Phone</dt>
                <dd><a href="tel:+254743417198" class="text-emerald-700 underline hover:text-emerald-800">+254 743 417 198</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-stone-500">Website</dt>
                <dd><a href="https://haroldkerry.co.ke" class="text-emerald-700 underline hover:text-emerald-800">haroldkerry.co.ke</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-stone-500">Studio</dt>
                <dd><a href="https://volcha.co.ke" class="text-emerald-700 underline hover:text-emerald-800">volcha.co.ke</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-stone-500">GitHub</dt>
                <dd><a href="https://github.com/harrykerry" class="text-emerald-700 underline hover:text-emerald-800">github.com/harrykerry</a></dd>
            </div>
        </dl>

        <p class="mt-10 border-t border-stone-300/70 pt-6 text-sm text-stone-500">
            Health check at <a href="/up" class="text-emerald-700 underline hover:text-emerald-800">/up</a>.
            MIT licensed.
        </p>
    </section>
    </main>
</div>
