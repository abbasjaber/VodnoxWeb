<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SettingsRequest;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        return view('company.settings', compact('org'));
    }

    public function update(SettingsRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $old = $org->only(['name_ar', 'name_en', 'contact_name', 'email', 'phone', 'city', 'timezone', 'language']);

        $org->fill([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'timezone' => $data['timezone'],
            'language' => $data['language'],
        ])->save();

        $this->audit->log('organization.settings_updated', $org, $old, $org->only(['name_ar', 'name_en', 'contact_name', 'email', 'phone', 'city', 'timezone', 'language']), null, $org->id);

        return redirect()->route('company.settings.index')->with('status', __('company.updated'));
    }
}
