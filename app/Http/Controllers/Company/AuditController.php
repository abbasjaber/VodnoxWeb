<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));

        $logs = AuditLog::query()
            ->where('organization_id', $org->id)
            ->with('user')
            ->when($q !== '', fn ($query) => $query->where('action', 'like', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('company.audit', compact('org', 'logs', 'q'));
    }
}
