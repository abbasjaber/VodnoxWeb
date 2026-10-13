<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller (Backend-only)
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application via
    | session-based authentication. It intentionally returns JSON responses
    | and does not render any Blade views, because the frontend is delivered
    | later as a separate concern. Registration is disabled at the route
    | level; password reset and email verification are not enabled in this
    | foundational phase.
    |
    */

    use AuthenticatesUsers;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Show the application's login form.
     *
     * No login view is shipped in this phase. Return a 404 so the route
     * does not leak a missing-view error. Clients should POST credentials
     * directly to /login.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function showLoginForm()
    {
        abort(404);
    }

    /**
     * The user has been authenticated.
     *
     * Regenerate the session to prevent fixation, then return a JSON
     * response instead of redirecting to a non-existent dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function authenticated(Request $request, $user)
    {
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => __('auth.success'),
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
            'errors' => null,
        ], 200);
    }

    /**
     * Get the failed login response instance.
     *
     * Return JSON 422 validation errors instead of redirecting back.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ])->status(422);
    }

    /**
     * Log the user out of the application.
     *
     * Invalidate the session and regenerate the CSRF token, then return
     * JSON. The route is registered as POST only.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => __('auth.logged_out'),
            'data' => null,
            'errors' => null,
        ], 200);
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return auth()->guard();
    }
}
