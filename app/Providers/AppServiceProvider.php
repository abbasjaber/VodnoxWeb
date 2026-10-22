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
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelephonyGateway::class, function () {
            // Preferred: the real provisioning agent configured through the
            // environment (see config/integrations.php and deploy/telephony).
            $url = (string) config('integrations.telephony.provisioning_url', '');

            if ($url !== '' && config('integrations.telephony.enabled')) {
                return new HttpTelephonyGateway([
                    'base_url' => $url,
                    'token' => (string) config('integrations.telephony.token', ''),
                    'signing_secret' => (string) config('integrations.telephony.signing_secret', ''),
                    'timeout' => (int) config('integrations.defaults.timeout', 15),
                    'connect_timeout' => (int) config('integrations.defaults.connect_timeout', 5),
                ]);
            }

            $server = $this->resolveConfiguredServer();

            if ($server) {
                return new HttpTelephonyGateway([
                    'base_url' => $server->host,
                    'token' => $server->ami_secret,
                    'signing_secret' => (string) config('integrations.telephony.signing_secret', ''),
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
        View::share('assetv', static function (string $path): string {
            $fullPath = public_path($path);
            $separator = str_contains($path, '?') ? '&' : '?';
            $version = is_file($fullPath) ? filemtime($fullPath) : time();

            return asset($path).$separator.'v='.$version;
        });

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

        /*
        | Telephony Technical Settings sign-in.
        |
        | Keyed on the submitted username *and* the IP so neither a single
        | account nor a single source can be hammered. The web response is a
        | plain redirect back to the gate with an explicit message — it never
        | repeats the submitted values, so it cannot confirm a username.
        */
        RateLimiter::for('technical-login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(($request->input('username') ?: 'unknown').'|'.$request->ip())
                ->response(function (Request $request) {
                    $message = __('telephony_technical.too_many_attempts');

                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $message,
                            'data' => null,
                            'errors' => ['username' => [$message]],
                        ], 429);
                    }

                    return redirect()->route('company.gateways.index')->with('error', $message);
                });
        });
    }
}
