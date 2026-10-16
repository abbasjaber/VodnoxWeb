<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $from = (string) $request->query('from', now()->subDays(29)->toDateString());
        $to = (string) $request->query('to', now()->toDateString());

        if ($from === '' || $to === '' || $from > $to) {
            $from = now()->subDays(29)->toDateString();
            $to = now()->toDateString();
        }

        $base = fn () => DB::table('calls')
            ->where('calls.organization_id', $org->id)
            ->whereDate('calls.created_at', '>=', $from)
            ->whereDate('calls.created_at', '<=', $to);

        $total = (clone $base())->count();
        $answered = (clone $base())->where('calls.status', 'answered')->count();
        $avgDuration = (int) round((float) (clone $base())->where('calls.status', 'answered')->avg('calls.duration_seconds') ?? 0);

        $summary = [
            'total' => $total,
            'answered' => $answered,
            'missed' => $total - $answered,
            'avg_duration' => $avgDuration,
            'answer_rate' => $total > 0 ? (int) round($answered / $total * 100) : 0,
        ];

        $byStatus = (clone $base())
            ->select('calls.status', DB::raw('count(*) as total'))
            ->groupBy('calls.status')
            ->orderByDesc('total')
            ->get();

        $byDirection = (clone $base())
            ->select('calls.direction', DB::raw('count(*) as total'))
            ->groupBy('calls.direction')
            ->orderByDesc('total')
            ->get();

        $byAgent = (clone $base())
            ->leftJoin('users', 'users.id', '=', 'calls.user_id')
            ->select('calls.user_id', 'users.name as agent_name', DB::raw('count(*) as total'), DB::raw('sum(case when calls.status = "answered" then 1 else 0 end) as answered'))
            ->groupBy('calls.user_id', 'users.name')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $perDay = (clone $base())
            ->select(DB::raw('date(calls.created_at) as day'), DB::raw('count(*) as total'), DB::raw('sum(case when calls.status = "answered" then 1 else 0 end) as answered'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('company.reports', compact('org', 'summary', 'byStatus', 'byDirection', 'byAgent', 'perDay', 'from', 'to'));
    }
}
