<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;

class RecordingController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));

        $recordings = Recording::query()
            ->where('organization_id', $org->id)
            ->with('call')
            ->when($q !== '', fn ($query) => $query->where('file_name', 'like', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('company.recordings', compact('org', 'recordings', 'q'));
    }
}
