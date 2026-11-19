<?php

namespace App\Providers;

use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustConfiguredProxies();
        $this->defineRateLimits();
    }

    /**
     * Trust the proxies this deployment sits behind.
     *
     * Behind a load balancer or Cloudflare the real client address arrives
     * in a forwarded header. Until the proxy is trusted, every M-PESA
     * callback appears to come from it and the IP allowlist cannot work.
     * Left unset, nothing is trusted and the socket address is used.
     */
    protected function trustConfiguredProxies(): void
    {
        $proxies = config('mpesa.security.trusted_proxies');

        if (blank($proxies)) {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
    }

    /**
     * Define the rate limits the API routes are grouped by.
     *
     * Callbacks are limited per sending IP and generously, since Safaricom
     * retries. Pushes are limited per payer as well as per caller, so one
     * customer cannot exhaust the limit for everyone. All of them answer in
     * the same envelope as every other failure.
     */
    protected function defineRateLimits(): void
    {
        RateLimiter::for('mpesa-callbacks', fn (Request $request) => Limit::perMinute(
            (int) config('mpesa.rate_limits.callbacks')
        )->by($request->ip())->response($this->tooManyRequests()));

        RateLimiter::for('mpesa-push', fn (Request $request) => [
            Limit::perMinute((int) config('mpesa.rate_limits.push_per_payer'))
                ->by('payer:'.$request->input('msisdn', $request->ip()))
                ->response($this->tooManyRequests('Too many payment requests for this number. Try again shortly.')),
            Limit::perMinute((int) config('mpesa.rate_limits.push_per_caller'))
                ->by('caller:'.$request->ip())
                ->response($this->tooManyRequests()),
        ]);

        RateLimiter::for('mpesa-operations', fn (Request $request) => Limit::perMinute(
            (int) config('mpesa.rate_limits.operations')
        )->by($request->ip())->response($this->tooManyRequests()));

        RateLimiter::for('mpesa-reads', fn (Request $request) => Limit::perMinute(
            (int) config('mpesa.rate_limits.reads')
        )->by($request->ip())->response($this->tooManyRequests()));
    }

    /**
     * The response a throttled request gets.
     */
    protected function tooManyRequests(string $message = 'Too many requests. Try again shortly.'): callable
    {
        return fn () => ApiResponse::error(
            message: $message,
            status: HttpStatus::HTTP_TOO_MANY_REQUESTS,
            errorCode: 'rate_limit.exceeded',
        );
    }
}
