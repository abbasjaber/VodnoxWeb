<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AccountSettingsRequest;
use App\Models\AgentPreference;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The agent's own account details and calling preferences.
     *
     * Microphone / notification permissions are never requested here: the view
     * exposes explicit buttons so the browser only prompts on a user action.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $preference = AgentPreference::query()->firstOrNew(
            ['user_id' => $user->id],
            ['audio_device' => 'default', 'auto_answer' => false, 'do_not_disturb' => false],
        );

        return view('user.settings', [
            'organization' => $organization,
            'user' => $user,
            'preference' => $preference,
            'settings' => $preference->settings ?? [],
        ]);
    }

    public function update(AccountSettingsRequest $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $data = $request->validated();

        $user->phone = $data['phone'] ?? null;
        $user->save();

        $preference = AgentPreference::query()->firstOrNew(['user_id' => $user->id]);
        $old = $preference->only(['audio_device', 'auto_answer', 'do_not_disturb']);

        $settings = $preference->settings ?? [];
        $settings['ringtone'] = $request->boolean('ringtone');
        $settings['notifications'] = $request->boolean('notifications');

        $preference->fill([
            'audio_device' => $data['audio_device'] ?? 'default',
            'auto_answer' => $data['auto_answer'] ?? false,
            'do_not_disturb' => $data['do_not_disturb'] ?? false,
            'settings' => $settings,
        ])->save();

        $this->audit->log('agent.settings_updated', $preference, $old, [
            'audio_device' => $preference->audio_device,
            'auto_answer' => $preference->auto_answer,
            'do_not_disturb' => $preference->do_not_disturb,
        ], organizationId: $organization->id);

        return redirect()
            ->route('user.settings.index')
            ->with('success', __('portal.agent.preferences_saved'));
    }
}
