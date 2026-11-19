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
                'blurb' => 'An STK push asks a customer to pay. C2B is money they send on their own, which only ever reaches you as a callback.',
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
                'blurb' => 'B2C pays a customer, B2B pays a business, tax pays the KRA. Each answers twice: accepted now, the result later on a callback.',
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
                'blurb' => 'Saved payments, searchable and filterable. Every filter is optional, and nothing found is an empty page, not an error.',
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
                'blurb' => 'For Safaricom, not for you. Only Safaricom addresses get through, and the work happens on the queue. Validation is the exception: M-PESA waits for it.',
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

<div x-data="{ section: 'overview' }">

    <header class="bg-saf-green text-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-3.5">
            <span class="font-semibold">M-PESA for Laravel</span>
            <span class="text-sm text-white/90">
                {{ config('mpesa.environment') }} &middot; Laravel {{ app()->version() }} &middot; PHP {{ PHP_VERSION }}
            </span>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-6 py-10 lg:flex lg:gap-12 lg:py-14">

    {{-- Left-hand section nav. Sticky on a wide screen, stacked above the
         content on a narrow one, and it tracks the section being read. --}}
    <aside class="lg:w-56 lg:shrink-0">
        <div class="lg:sticky lg:top-10">
            <p class="font-semibold text-saf-ink">M-PESA for Laravel</p>

            <nav class="mt-6 flex flex-wrap gap-x-4 gap-y-1 border-b border-saf-line pb-4 text-sm lg:flex-col lg:gap-0 lg:border-b-0 lg:border-l lg:border-saf-line lg:pb-0">
                @foreach ([
                    'overview' => 'Overview',
                    'flow' => 'How it works',
                    'install' => 'Run it',
                    'configured' => 'Your settings',
                    'endpoints' => 'Endpoints',
                    'demo' => 'Try it',
                    'modify' => 'Change it',
                    'going-live' => 'Going live',
                    'contact' => 'Contact',
                ] as $anchor => $label)
                    <a href="#{{ $anchor }}"
                       @click="section = '{{ $anchor }}'"
                       :class="section === '{{ $anchor }}'
                           ? 'font-semibold text-saf-ink lg:border-saf-green'
                           : 'text-saf-muted hover:text-saf-ink lg:border-transparent'"
                       class="py-1 lg:-ml-px lg:border-l-2 lg:pl-4">{{ $label }}</a>
                @endforeach
            </nav>

            <dl class="mt-6 hidden space-y-1 text-xs text-saf-muted lg:block">
                <div class="flex justify-between gap-2"><dt>Laravel</dt><dd class="text-saf-body">{{ app()->version() }}</dd></div>
                <div class="flex justify-between gap-2"><dt>PHP</dt><dd class="text-saf-body">{{ PHP_VERSION }}</dd></div>
                <div class="flex justify-between gap-2"><dt>Daraja</dt><dd class="text-saf-body">{{ config('mpesa.environment') }}</dd></div>
                <div class="flex justify-between gap-2"><dt>Queue</dt><dd class="text-saf-body">{{ config('mpesa.queue.connection') ?: config('queue.default') }}</dd></div>
            </dl>
        </div>
    </aside>

    <main class="min-w-0 flex-1">

        <section id="overview" class="scroll-mt-10 border-b border-saf-line pb-10">
            <h1 class="text-3xl font-semibold tracking-tight text-saf-ink sm:text-4xl">
                Safaricom M-PESA for Laravel
            </h1>
            <p class="mt-4 text-saf-body">
                A Laravel backend for M-PESA. It handles STK push, C2B, B2C, B2B, tax, reversals, status,
                balance and QR codes.
            </p>
            <p class="mt-4 text-saf-body">
                Your frontend sends a phone number and an amount. Shortcodes, passkeys and credentials stay
                here, in one config file.
            </p>
        </section>
        {{-- How a payment moves --}}
        <section id="flow" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">How a payment moves</h2>
        <ol class="mt-5 space-y-5">
            @foreach ([
                ['A payment starts', 'You ask for an STK push, or a customer pays your shortcode on their own. Only the push starts with you.'],
                ['Safaricom calls back', 'We save the payload, answer straight away, and let a queued job do the work. A repeat of the same callback is ignored.'],
                ['Your system hears', 'The job saves the payment. If you set a forwarding URL, it is sent on to you, signed and retried.'],
            ] as $index => [$title, $copy])
                <li class="flex gap-4">
                    <span class="w-5 shrink-0 pt-0.5 text-sm text-saf-muted">{{ $index + 1 }}.</span>
                    <div>
                        <h3 class="font-medium text-saf-ink">{{ $title }}</h3>
                        <p class="mt-1 text-saf-body">{{ $copy }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="mt-5 text-saf-body">
            C2B validation is the exception. M-PESA waits for that one, so it answers at once: accept and the
            customer pays, reject and they keep their money.
        </p>
    </section>

    {{-- Install --}}
    <section id="install" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Run it</h2>
        <p class="mt-2 text-saf-body">With Docker, nothing else to install:</p>
        <pre class="mt-4 overflow-x-auto rounded-md p-4 text-[13px] leading-relaxed text-saf-bg"><code>cp .env.example .env
docker compose up -d                        # app, queue worker, redis, mysql
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# prefer RabbitMQ? set QUEUE_CONNECTION=rabbitmq in .env, then
docker compose --profile rabbitmq up -d</code></pre>
        <p class="mt-3 text-saf-body">
            The app is on <a href="http://localhost:8000" class="text-saf-green underline hover:text-saf-dark">localhost:8000</a>.
            RabbitMQ's dashboard is on <a href="http://localhost:15672" class="text-saf-green underline hover:text-saf-dark">localhost:15672</a>, guest / guest.
        </p>

        <p class="mt-7 text-saf-body">Or run it yourself:</p>
        <pre class="mt-4 overflow-x-auto rounded-md p-4 text-[13px] leading-relaxed text-saf-bg"><code>composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# add your Daraja values to .env, then make a key for your frontend
php artisan mpesa:api-key --for=checkout

# the callbacks need a worker
php artisan queue:work --queue=mpesa

# last, tell Safaricom where to call
curl -X POST http://localhost:8000/api/v1/mpesa/c2b/register-urls \
  -H "X-API-Key: your-key"</code></pre>

    </section>

        <section id="configured" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Your configuration</h2>
        <p class="mt-1 text-sm text-saf-muted">Read live from your environment. Values are never shown, only whether they are set.</p>
        <ul class="mt-4 divide-y divide-saf-line border-y border-saf-line">
            @foreach ($this->checklist() as $item)
                <li class="flex items-baseline justify-between gap-4 py-2.5">
                    <span>
                        <span class="text-saf-ink">{{ $item['label'] }}</span>
                        <code class="ml-2 text-xs text-saf-muted">{{ $item['hint'] }}</code>
                    </span>
                    <span class="shrink-0 text-sm {{ $item['ready'] ? 'font-medium text-saf-green' : 'text-saf-muted' }}">
                        {{ $item['ready'] ? 'set' : 'not set' }}
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Endpoints --}}
    <section id="endpoints" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Endpoints</h2>
        <p class="mt-2 text-saf-body">
            All under <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">/api/v1</code>. Breaking changes go
            in a v2 group, so v1 keeps working.
        </p>

        <div class="mt-6 flex flex-wrap gap-2 text-sm">
            @foreach ($this->groups() as $key => $group)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="rounded-full border px-4 py-1.5 transition {{ $tab === $key
                        ? 'border-saf-green bg-saf-green font-medium text-white'
                        : 'border-saf-line bg-white text-saf-body hover:border-saf-green hover:text-saf-ink' }}">
                    {{ $group['title'] }}
                </button>
            @endforeach
        </div>

        @php($group = $this->groups()[$tab])
        <div wire:key="group-{{ $tab }}">
            <p class="mt-5 text-saf-body">{{ $group['blurb'] }}</p>

            <ul class="mt-5 divide-y divide-saf-line border-y border-saf-line">
                @foreach ($group['routes'] as $route)
                    <li class="py-3">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="w-12 shrink-0 text-xs font-medium text-saf-muted">{{ $route['method'] }}</span>
                            <code class="text-sm text-saf-ink">{{ $route['path'] }}</code>
                        </div>
                        <p class="mt-1 pl-14 text-sm text-saf-body">{{ $route['note'] }}</p>
                    </li>
                @endforeach
            </ul>

            <pre class="mt-6 overflow-x-auto rounded-md p-4 text-[13px] leading-relaxed text-saf-bg"><code>{{ $group['example'] }}</code></pre>
        </div>
    </section>

    {{-- Demo --}}
    <section id="demo" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Try it</h2>
        <p class="mt-2 text-saf-body">
            This form calls <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">MpesaStkService::initiatePush()</code>,
            the same service the API uses. The services need no HTTP request, so they work from Blade, Livewire,
            a job or a command just as well.
        </p>

        @if ($this->demoAvailable())
            <form wire:submit="sendDemoPush" class="mt-6 flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="block text-sm text-saf-body">Amount (KES)</span>
                    <input type="number" wire:model="amount" min="1"
                        class="mt-1 w-32 rounded border border-saf-line bg-white text-saf-ink px-3 py-2 outline-none focus:border-saf-green">
                </label>
                <label class="block">
                    <span class="block text-sm text-saf-body">Mobile number</span>
                    <input type="text" wire:model="msisdn" placeholder="0712345678"
                        class="mt-1 w-44 rounded border border-saf-line bg-white text-saf-ink px-3 py-2 outline-none focus:border-saf-green">
                </label>
                <button type="submit" wire:loading.attr="disabled"
                    class="rounded bg-saf-green px-4 py-2 font-medium text-white hover:bg-saf-dark disabled:opacity-60">
                    <span wire:loading.remove wire:target="sendDemoPush">Send the prompt</span>
                    <span wire:loading wire:target="sendDemoPush">Asking M-PESA…</span>
                </button>
            </form>
            @error('amount') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('msisdn') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        @else
            <p class="mt-6 rounded bg-[#f1f1f1] px-4 py-3 text-sm text-saf-body">
                The demo is off. Turn it on with <code>MPESA_DEMO_ENABLED=true</code> and sandbox credentials. It
                stays off by default so it cannot send a real payment request.
            </p>
        @endif

        @if ($demoError)
            <p class="mt-4 rounded bg-[#fdecee] px-4 py-3 text-sm text-saf-ink">{{ $demoError }}</p>
        @endif

        @if ($demoResult)
            <div class="mt-4 border-l-2 border-white/60 bg-white/10 px-4 py-3 text-sm text-saf-body">
                <p class="font-medium text-saf-ink">{{ $demoResult['customer_message'] }}</p>
                <p class="mt-1"><code>checkout_request_id</code>: {{ $demoResult['checkout_request_id'] }}</p>
                <p><code>merchant_request_id</code>: {{ $demoResult['merchant_request_id'] }}</p>
            </div>
        @endif

        <h3 class="mt-8 font-medium text-saf-ink">What has come in</h3>
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
                    <div class="flex items-baseline justify-between gap-3 border-b border-saf-line py-1.5">
                        <dt class="text-saf-body">{{ $label }}</dt>
                        <dd class="font-medium text-saf-ink">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            <button type="button" wire:click="$refresh" class="mt-3 text-sm text-saf-green underline hover:text-saf-dark">Refresh</button>
        @else
            <p class="mt-2 text-saf-body">Run <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">php artisan migrate</code> to create the tables.</p>
        @endif
    </section>

    {{-- Changing it --}}
    <section id="modify" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Change it</h2>
        <p class="mt-2 text-saf-body">Where to go for the things you are most likely to want.</p>
        <dl class="mt-5 space-y-5">
            @foreach ([
                ['Turn a payment away', 'MpesaC2bValidationService::checks()', 'Return a rejection and the customer keeps their money. Return null and the payment goes through. Amount, shortcode and account rules are already there.'],
                ['Use a payout or reversal result', 'MpesaResultService::handle()', 'Every slow API reports back here, with its original request already matched up and saved.'],
                ['Send payments to another system', 'config/mpesa.php, forwarding', 'Set a URL and each confirmed payment is posted there, signed and retried. Leave it empty and nothing is sent.'],
                ['Use RabbitMQ instead of Redis', '.env, QUEUE_CONNECTION', 'Both are configured. Set QUEUE_CONNECTION=rabbitmq, or MPESA_QUEUE_CONNECTION=rabbitmq for the M-PESA jobs only.'],
                ['Add your own frontend', 'App\Services', 'Inject any M-PESA service into a controller, component, job or command. None of them touch a request.'],
                ['Break the API safely', 'routes/api.php', 'Add a v2 group next to v1. Old callers stay on v1 until they move.'],
            ] as [$title, $where, $copy])
                <div>
                    <dt class="font-medium text-saf-ink">{{ $title }}</dt>
                    <dd class="mt-1 text-saf-body">
                        <code class="text-sm text-saf-green">{{ $where }}</code>
                        <span class="mt-1 block">{{ $copy }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Going live --}}
    <section id="going-live" class="scroll-mt-10 border-b border-saf-line py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Going live</h2>
        <ul class="mt-5 space-y-2.5 text-saf-body">
            <li>Keep API keys out of the browser. Hold them in your backend and call this API from there.</li>
            <li>Give each caller its own key with <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">php artisan mpesa:api-key</code>, so you can revoke one alone.</li>
            <li>Send an <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">Idempotency-Key</code> with each checkout, so a retry cannot prompt twice.</li>
            <li>Behind a load balancer, set <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">TRUSTED_PROXIES</code>, or every callback looks like the proxy.</li>
            <li>Set <code class="rounded bg-saf-tint px-1.5 py-0.5 text-sm">MPESA_ALLOWED_HOSTS</code> to the domain you gave Safaricom.</li>
            <li>Callback URLs must be HTTPS and open: M-PESA cannot send your headers.</li>
        </ul>
    </section>

    {{-- Contact --}}
    <section id="contact" class="scroll-mt-10 py-10">
        <h2 class="text-xl font-semibold text-saf-ink">Contact</h2>
        <p class="mt-2 text-saf-body">Built by Harold Kerry. Glad to help with an integration or a go-live.</p>
        <dl class="mt-5 grid gap-2 text-sm sm:grid-cols-2">
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-saf-muted">Email</dt>
                <dd><a href="mailto:haroldkerry@gmail.com" class="text-saf-green underline hover:text-saf-dark">haroldkerry@gmail.com</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-saf-muted">Phone</dt>
                <dd><a href="tel:+254743417198" class="text-saf-green underline hover:text-saf-dark">+254 743 417 198</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-saf-muted">Website</dt>
                <dd><a href="https://haroldkerry.co.ke" class="text-saf-green underline hover:text-saf-dark">haroldkerry.co.ke</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-saf-muted">Studio</dt>
                <dd><a href="https://volcha.co.ke" class="text-saf-green underline hover:text-saf-dark">volcha.co.ke</a></dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-20 shrink-0 text-saf-muted">GitHub</dt>
                <dd><a href="https://github.com/harrykerry" class="text-saf-green underline hover:text-saf-dark">github.com/harrykerry</a></dd>
            </div>
        </dl>

        <p class="mt-10 border-t border-saf-line pt-6 text-sm text-saf-muted">
            Health check at <a href="/up" class="text-saf-green underline hover:text-saf-dark">/up</a>.
            MIT licensed.
        </p>
    </section>
    </main>
    </div>

    <footer class="bg-saf-green text-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-5 text-sm">
            <span>{{ config('app.name') }}</span>
            <span class="text-white/90">
                MIT licensed &middot;
                <a href="/up" class="underline decoration-white/50 hover:decoration-white">health check</a>
            </span>
        </div>
    </footer>
</div>
