<?php

namespace App\Providers;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\TelephonyServer;
use App\Telephony\HttpTelephonyGateway;
use App\Telephony\SimulatedTelephonyGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelephonyGateway::class, function () {
            $server = $this->resolveConfiguredServer();

            if ($server) {
                return new HttpTelephonyGateway([
                    'base_url' => $server->host,
                    'token' => $server->ami_secret,
                    'timeout' => (int) config('integrations.defaults.timeout', 15),
                ]);
            }

            return new SimulatedTelephonyGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * The telephony server row is only consulted when the app is booted against
     * a database; failures fall back to simulation so the app stays usable.
     */
    protected function resolveConfiguredServer(): ?TelephonyServer
    {
        try {
            if (! Schema::hasTable('telephony_servers')) {
                return null;
            }

            return TelephonyServer::query()
                ->where('status', 'connected')
                ->whereNotNull('host')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Configure application rate limiters.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            // The login form authenticates by username, not e-mail. Key the
            // limiter on the submitted username so the per-account throttle
            // actually applies instead of collapsing into one bucket per IP.
            return Limit::perMinute(5)
                ->by(($request->input('username') ?: $request->input('email')).'|'.$request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'message' => __('auth.throttle'),
                        'data' => null,
                        'errors' => ['login' => [__('auth.throttle')]],
                    ], 429);
                });
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
