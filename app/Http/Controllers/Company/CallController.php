<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Http\Request;

class CallController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $direction = (string) $request->query('direction', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $calls = $org->calls()
            ->with('user')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('caller_number', 'like', "%{$q}%")
                        ->orWhere('callee_number', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, ['ringing', 'answered', 'unanswered', 'busy', 'failed', 'ended'], true), fn ($query) => $query->where('status', $status))
            ->when(in_array($direction, ['inbound', 'outbound', 'internal'], true), fn ($query) => $query->where('direction', $direction))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('company.calls', compact('org', 'calls', 'q', 'status', 'direction', 'from', 'to'));
    }

    public function show(Request $request, Call $call)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $call);

        $call->load(['user', 'customer', 'events', 'recordings']);

        return view('company.call-show', compact('org', 'call'));
    }
}
