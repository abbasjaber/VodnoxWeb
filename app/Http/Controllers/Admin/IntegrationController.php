<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IntegrationRequest;
use App\Models\Integration;
use App\Services\AuditService;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $integrations = Integration::latest()->get();

        return view('admin.integrations', [
            'integrations' => $integrations,
        ]);
    }

    public function store(IntegrationRequest $request)
    {
        $data = $request->validated();
        $config = array_filter($this->parseConfig($data['config_text'] ?? null), fn ($value) => $value !== '');

        $integration = Integration::create([
            'type' => $data['type'],
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'config' => $config,
        ]);

        // Only key names are audited; credential values are never logged.
        $this->audit->log('integration.created', $integration, [], ['type' => $integration->type, 'config_keys' => array_keys($config)]);

        return back()->with('status', __('admin.flash_created'));
    }

    public function update(IntegrationRequest $request, Integration $integration)
    {
        $data = $request->validated();
        $incoming = $this->parseConfig($data['config_text'] ?? null);
        $existing = $integration->config ?? [];

        // Blank values keep the stored credential untouched.
        foreach ($incoming as $key => $value) {
            if ($value !== '') {
                $existing[$key] = $value;
            }
        }

        $integration->update([
            'type' => $data['type'],
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'config' => $existing,
        ]);

        $this->audit->log('integration.updated', $integration, [], ['type' => $integration->type, 'config_keys' => array_keys($existing)]);

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(Integration $integration)
    {
        $integration->delete();

        $this->audit->log('integration.deleted', null, ['type' => $integration->type, 'name' => $integration->name], []);

        return back()->with('status', __('admin.flash_deleted'));
    }

    /**
     * Parse "key=value" lines into an array. Values are never echoed back.
     *
     * @return array<string, string>
     */
    private function parseConfig(?string $text): array
    {
        $config = [];

        if (! $text) {
            return $config;
        }

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $config[$key] = trim($value);
        }

        return $config;
    }
}
