<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\ContactFavorite;
use App\Models\Task;
use App\Models\Voicemail;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesTenant;

    /**
     * In-app dialer. The telephony UI is owned by user/_phone_card.blade.php,
     * which talks to the real SIP stack through the authenticated
     * user.telephony.credentials endpoint and only ever reports the states the
     * SIP layer actually emits.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $myCalls = Call::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id);

        $stats = [
            'calls_today' => (clone $myCalls)->whereDate('created_at', today())->count(),
            'missed_incoming' => (clone $myCalls)->where('direction', 'inbound')->where('status', 'missed')->count(),
            'talk_time' => (int) (clone $myCalls)->sum('duration_seconds'),
            'tasks' => Task::query()
                ->where('organization_id', $organization->id)
                ->where('assigned_to', $user->id)
                ->where('status', 'pending')
                ->count(),
            'voicemails' => Voicemail::query()
                ->where('organization_id', $organization->id)
                ->where(function ($query) use ($user) {
                    $query->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->where('is_read', false)
                ->count(),
        ];

        $recentCalls = (clone $myCalls)->orderByDesc('started_at')->orderByDesc('id')->limit(5)->get();

        $favorites = ContactFavorite::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return view('user.index', compact('organization', 'stats', 'recentCalls', 'favorites'));
    }
}
