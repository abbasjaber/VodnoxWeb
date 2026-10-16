<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->with(['user', 'organization']);

        if ($q = trim((string) $request->query('q'))) {
            $query->where('action', 'like', "%{$q}%");
        }

        if ($organizationId = $request->query('organization')) {
            $query->where('organization_id', $organizationId);
        }

        $logs = $query->latest()->paginate(20)->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'organizations' => Organization::orderBy('name_ar')->get(),
            'q' => $request->query('q', ''),
            'organization' => $request->query('organization', ''),
        ]);
    }
}
