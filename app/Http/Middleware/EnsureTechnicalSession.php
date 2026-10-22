<?php

namespace App\Http\Middleware;

use App\Services\Telephony\TechnicalSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate of the "Telephony Technical Settings" section.
 *
 * Every protected route — page, form post, JSON call or download — passes
 * through here, so a website account that merely *holds* the gateway
 * permissions (including `view_gateway_secrets` and `rotate_gateway_secrets`)
 * still cannot read a secret, rotate one, or change a device without a live
 * technical session.
 *
 * This is authentication and authorisation. It does not "encrypt" the section,
 * and hiding the navigation entry protects nothing on its own.
 */
class EnsureTechnicalSession
{
    public function __construct(
        private readonly TechnicalSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reason = $this->sessions->evaluate($request);

        if ($reason !== null) {
            // Clear a half-dead session so the next request starts clean.
            $this->sessions->end($request);

            $message = __('telephony_technical.session_required');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'data' => null,
                    'errors' => null,
                ], 401)->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                ]);
            }

            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return redirect()->route('company.gateways.index')->with('error', $message);
            }

            abort(403, $message);
        }

        $this->sessions->touch($request);

        return $next($request);
    }
}
