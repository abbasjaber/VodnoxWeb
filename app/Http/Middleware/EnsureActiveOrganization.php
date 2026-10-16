<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->organization_id) {
            abort(403, 'No organization assigned.');
        }

        if (! $user->organization || ! $user->organization->isActive()) {
            abort(403, 'Organization is not active.');
        }

        return $next($request);
    }
}
