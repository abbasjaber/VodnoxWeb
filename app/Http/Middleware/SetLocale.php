<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * The locales supported by the application.
     *
     * @var array<int, string>
     */
    protected array $supported = ['ar', 'en'];

    /**
     * Handle an incoming request.
     *
     * Sets the application locale from the session (web) or the
     * Accept-Language header (API). Only 'ar' and 'en' are accepted;
     * any other value falls back to the configured default locale.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);

        return $next($request);
    }

    /**
     * Resolve the locale for the current request.
     */
    protected function resolveLocale(Request $request): string
    {
        $locale = null;

        if ($request->is('api/*')) {
            $locale = $request->header('Accept-Language');
        } else {
            $locale = $request->session()->get('locale');
        }

        $locale = $this->normalize($locale);

        if (! in_array($locale, $this->supported, true)) {
            $locale = config('app.locale');
        }

        return $locale;
    }

    /**
     * Normalize a locale string to a supported two-letter code.
     *
     * Accept-Language headers may contain values such as "ar-SA,ar;q=0.9".
     */
    protected function normalize(?string $locale): ?string
    {
        if (empty($locale)) {
            return null;
        }

        $locale = explode(',', $locale)[0];
        $locale = explode(';', $locale)[0];
        $locale = strtolower(trim($locale));

        if (strlen($locale) >= 2) {
            return substr($locale, 0, 2);
        }

        return null;
    }
}
