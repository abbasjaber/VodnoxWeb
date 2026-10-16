<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageController extends Controller
{
    public function index(Request $request)
    {
        $query = Organization::query()->withCount(['users', 'extensions']);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%");
            });
        }

        $organizations = $query->orderBy('name_ar')->paginate(15)->withQueryString();

        $ids = $organizations->pluck('id')->all();

        $callsThisMonth = DB::table('calls')
            ->select('organization_id', DB::raw('COUNT(*) as total'))
            ->whereIn('organization_id', $ids ?: [0])
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        $storage = DB::table('recordings')
            ->select('organization_id', DB::raw('COALESCE(SUM(size),0) as total'))
            ->whereIn('organization_id', $ids ?: [0])
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        $numbers = DB::table('phone_numbers')
            ->select('organization_id', DB::raw('COUNT(*) as total'))
            ->whereIn('organization_id', $ids ?: [0])
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        return view('admin.usage', [
            'organizations' => $organizations,
            'callsThisMonth' => $callsThisMonth,
            'storage' => $storage,
            'numbers' => $numbers,
            'q' => $request->query('q', ''),
        ]);
    }
}
