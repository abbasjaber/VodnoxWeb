<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Voicemail;
use Illuminate\Http\Request;

class VoicemailController extends Controller
{
    use ResolvesTenant;

    /**
     * Voicemail messages scoped to the agent's organization. Messages assigned
     * to a specific agent are only shown to that agent; unassigned organization
     * messages are visible to everyone in the organization.
     *
     * There is no telephony integration, so playback is a placeholder only.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $voicemails = Voicemail::query()
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($user) {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('user.voicemail', [
            'organization' => $organization,
            'voicemails' => $voicemails,
        ]);
    }
}
