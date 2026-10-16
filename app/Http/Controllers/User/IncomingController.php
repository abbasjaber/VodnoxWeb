<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\CallPanelRequest;
use App\Models\Call;
use App\Models\CallNote;
use App\Models\CallWrapup;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Followup;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Incoming-call workspace: real persistence for notes, complaints, follow-ups
 * and the call wrap-up.
 *
 * There is no telephony integration. A "session" is a locally opened Call row
 * (identified by the SESSION_PREFIX) that the agent documents while talking.
 * A new session cannot be started while a wrap-up is still open
 * (call_wrapups.is_completed = false), which is enforced here server-side.
 */
class IncomingController extends Controller
{
    use ResolvesTenant;

    protected const SESSION_PREFIX = 'local-session:';

    protected string $view = 'user.incoming';

    protected string $route = 'user.incoming.index';

    protected string $auditPrefix = 'agent.incoming';

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $session = $this->activeCall($organization, $user);
        $draftWrapup = $session
            ? CallWrapup::query()->where('call_id', $session->id)->first()
            : $this->pendingWrapup($organization, $user);

        $notes = $session
            ? CallNote::query()->where('call_id', $session->id)->orderByDesc('id')->get()
            : collect();
        $complaints = $session
            ? Complaint::query()->where('call_id', $session->id)->orderByDesc('id')->get()
            : collect();
        $followups = $session
            ? Followup::query()->where('call_id', $session->id)->orderByDesc('id')->get()
            : collect();

        $recentSessions = CallWrapup::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('is_completed', true)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $customers = Customer::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name_ar')
            ->limit(200)
            ->get(['id', 'name_ar', 'name_en', 'phone']);

        $departments = Department::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en']);

        $agents = User::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view($this->view, [
            'organization' => $organization,
            'session' => $session,
            'draftWrapup' => $draftWrapup,
            'notes' => $notes,
            'complaints' => $complaints,
            'followups' => $followups,
            'recentSessions' => $recentSessions,
            'customers' => $customers,
            'departments' => $departments,
            'agents' => $agents,
        ]);
    }

    public function store(CallPanelRequest $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $data = $request->validated();

        $action = $data['action'];

        if ($action === 'start') {
            return $this->startSession($organization, $user, $data);
        }

        $session = $this->activeCall($organization, $user);

        if (! $session) {
            throw ValidationException::withMessages([
                'action' => __('portal.agent.no_session'),
            ]);
        }

        return match ($action) {
            'note' => $this->storeNote($organization, $user, $session, $data),
            'complaint' => $this->storeComplaint($organization, $user, $session, $data),
            'followup' => $this->storeFollowup($organization, $user, $session, $data),
            'wrapup' => $this->saveWrapup($organization, $user, $session, $data, false),
            'finish' => $this->saveWrapup($organization, $user, $session, $data, true),
            default => throw ValidationException::withMessages([
                'action' => __('portal.agent.validation_failed'),
            ]),
        };
    }

    /**
     * A new session is refused while the previous wrap-up is still open.
     */
    protected function startSession($organization, $user, array $data)
    {
        if ($this->pendingWrapup($organization, $user)) {
            throw ValidationException::withMessages([
                'action' => __('portal.agent.session_blocked'),
            ]);
        }

        if ($this->activeCall($organization, $user)) {
            throw ValidationException::withMessages([
                'action' => __('portal.agent.session_active'),
            ]);
        }

        $call = Call::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'external_call_id' => self::SESSION_PREFIX.Str::uuid(),
            'customer_id' => $data['customer_id'] ?? null,
            'direction' => 'inbound',
            'caller_number' => $data['caller_number'] ?? null,
            'callee_number' => $user->extension,
            'status' => 'answered',
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $this->audit->log($this->auditPrefix.'.session_started', $call, [], [
            'caller_number' => $call->caller_number,
        ], organizationId: $organization->id);

        return redirect()
            ->route($this->route)
            ->with('success', __('portal.agent.session_started'));
    }

    protected function storeNote($organization, $user, Call $session, array $data)
    {
        $content = $data['content'] ?? $data['description'] ?? null;

        if (! $content) {
            throw ValidationException::withMessages(['content' => __('portal.agent.validation_failed')]);
        }

        $note = CallNote::create([
            'call_id' => $session->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'customer_id' => $data['customer_id'] ?? $session->customer_id,
            'content' => $content,
        ]);

        $this->audit->log($this->auditPrefix.'.note_saved', $note, [], [
            'call_id' => $session->id,
        ], organizationId: $organization->id);

        return redirect()
            ->route($this->route)
            ->with('success', __('portal.agent.note_saved'));
    }

    protected function storeComplaint($organization, $user, Call $session, array $data)
    {
        if (empty($data['subject']) || empty($data['description'])) {
            throw ValidationException::withMessages(['subject' => __('portal.agent.validation_failed')]);
        }

        $complaint = Complaint::create([
            'organization_id' => $organization->id,
            'customer_id' => $data['customer_id'] ?? $session->customer_id,
            'call_id' => $session->id,
            'department_id' => $data['department_id'] ?? null,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
            'assigned_to' => $data['assigned_to'] ?? null,
        ]);

        $this->audit->log($this->auditPrefix.'.complaint_saved', $complaint, [], [
            'call_id' => $session->id,
            'priority' => $complaint->priority,
        ], organizationId: $organization->id);

        return redirect()
            ->route($this->route)
            ->with('success', __('portal.agent.complaint_saved'));
    }

    protected function storeFollowup($organization, $user, Call $session, array $data)
    {
        if (empty($data['title'])) {
            throw ValidationException::withMessages(['title' => __('portal.agent.validation_failed')]);
        }

        $followup = Followup::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'customer_id' => $data['customer_id'] ?? $session->customer_id,
            'call_id' => $session->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => $this->toUtc($data['due_at'] ?? null),
            'status' => 'pending',
        ]);

        $this->audit->log($this->auditPrefix.'.followup_saved', $followup, [], [
            'call_id' => $session->id,
            'due_at' => optional($followup->due_at)->toIso8601String(),
        ], organizationId: $organization->id);

        return redirect()
            ->route($this->route)
            ->with('success', __('portal.agent.followup_saved'));
    }

    /**
     * Persist the wrap-up. `$complete` closes the session (is_completed = true).
     */
    protected function saveWrapup($organization, $user, Call $session, array $data, bool $complete)
    {
        if ($complete && (empty($data['outcome']) || empty($data['summary']))) {
            throw ValidationException::withMessages([
                'summary' => __('portal.agent.validation_failed'),
            ]);
        }

        $wrapup = CallWrapup::firstOrNew(['call_id' => $session->id]);
        $old = $wrapup->only(['outcome', 'summary', 'is_completed']);

        $wrapup->fill([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'customer_id' => $data['customer_id'] ?? $session->customer_id,
            'is_completed' => $complete,
        ]);

        if (! empty($data['outcome'])) {
            $wrapup->outcome = $data['outcome'];
        }

        if (! empty($data['summary'])) {
            $wrapup->summary = $data['summary'];
        }

        $wrapup->save();

        if ($complete) {
            $session->forceFill([
                'ended_at' => now(),
            ])->save();
        }

        $this->audit->log(
            $this->auditPrefix.($complete ? '.session_finished' : '.wrapup_draft_saved'),
            $wrapup,
            $old,
            ['outcome' => $wrapup->outcome, 'is_completed' => $wrapup->is_completed],
            organizationId: $organization->id,
        );

        return redirect()
            ->route($this->route)
            ->with('success', __($complete ? 'portal.agent.session_finished' : 'portal.agent.draft_saved'));
    }

    protected function pendingWrapup($organization, $user): ?CallWrapup
    {
        return CallWrapup::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('is_completed', false)
            ->orderByDesc('id')
            ->first();
    }

    protected function activeCall($organization, $user): ?Call
    {
        return Call::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('external_call_id', 'like', self::SESSION_PREFIX.'%')
            ->whereDoesntHave('wrapup', fn ($query) => $query->where('is_completed', true))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Convert an agent-entered local datetime (Asia/Baghdad) to UTC for storage.
     */
    protected function toUtc(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
