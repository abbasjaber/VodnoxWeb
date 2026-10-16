<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request)
    {
        return view('admin.settings', [
            'values' => $this->settings->all(),
        ]);
    }

    public function update(SettingsRequest $request)
    {
        $values = $request->validated()['settings'];

        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }

        $this->audit->log('settings.updated', null, [], ['keys' => array_keys($values)], null, null);

        return back()->with('status', __('admin.flash_updated'));
    }
}
