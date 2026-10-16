<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Task;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    use ResolvesTenant;

    /**
     * The agent's own call history only (Call.user_id = authenticated user).
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $direction = (string) $request->query('direction', '');
        $status = (string) $request->query('status', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $calls = Call::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->when(in_array($direction, ['inbound', 'outbound'], true), fn ($query) => $query->where('direction', $direction))
            ->when(in_array($status, ['answered', 'missed', 'ringing', 'cancelled'], true), fn ($query) => $query->where('status', $status))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('user.history', [
            'organization' => $organization,
            'calls' => $calls,
            'filters' => [
                'direction' => $direction,
                'status' => $status,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * Tasks assigned to the agent.
     */
    public function tasks(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $status = (string) $request->query('status', '');

        $tasks = Task::query()
            ->where('organization_id', $organization->id)
            ->where('assigned_to', $user->id)
            ->when(in_array($status, ['pending', 'in_progress', 'completed'], true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('user.tasks', [
            'organization' => $organization,
            'tasks' => $tasks,
            'status' => $status,
        ]);
    }
}
