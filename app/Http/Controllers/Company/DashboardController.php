<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $totalCalls = $org->calls()->count();
        $answered = $org->calls()->where('status', 'answered')->count();

        $stats = [
            'team' => $org->users()->count(),
            'extensions' => $org->extensions()->count(),
            'departments' => $org->departments()->count(),
            'queues' => CallQueue::query()->where('organization_id', $org->id)->count(),
            'calls_today' => $org->calls()->whereDate('created_at', today())->count(),
            'answer_rate' => $totalCalls > 0 ? (int) round($answered / $totalCalls * 100) : 0,
        ];

        $recentCalls = $org->calls()->latest('id')->limit(5)->get();
        $teamMembers = $org->users()->with('role')->latest('id')->limit(5)->get();
        $subscription = $org->subscriptions()->latest('id')->first();
        $featureSubscriptions = $org->featureSubscriptions()->with('feature')->get();

        return view('company.index', compact('org', 'stats', 'recentCalls', 'teamMembers', 'subscription', 'featureSubscriptions'));
    }
}
