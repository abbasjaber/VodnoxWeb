<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\IvrMenuRequest;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\Request;

class IvrController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $menus = IvrMenu::query()
            ->where('organization_id', $org->id)
            ->with('options')
            ->orderBy('id')
            ->get();

        $extensions = $org->extensions()->orderBy('extension')->get();
        $queues = CallQueue::query()->where('organization_id', $org->id)->orderBy('id')->get();

        // Destination label maps for display.
        $extensionMap = $extensions->mapWithKeys(fn ($e) => [$e->id => $e->extension.($e->label ? ' — '.$e->label : '')]);
        $queueMap = $queues->mapWithKeys(fn ($q) => [$q->id => $q->name_ar]);
        $menuMap = $menus->mapWithKeys(fn ($m) => [$m->id => $m->name_ar]);

        return view('company.ivr', compact('org', 'menus', 'extensions', 'queues', 'extensionMap', 'queueMap', 'menuMap'));
    }

    public function store(IvrMenuRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $menu = IvrMenu::create([
            'organization_id' => $org->id,
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'greeting_ar' => $data['greeting_ar'] ?? null,
            'greeting_en' => $data['greeting_en'] ?? null,
            'timeout' => $data['timeout'],
            'max_attempts' => $data['max_attempts'],
            'invalid_destination_type' => $data['invalid_destination_type'],
            'invalid_destination_id' => $data['invalid_destination_id'] ?? null,
            'no_input_destination_type' => $data['no_input_destination_type'],
            'no_input_destination_id' => $data['no_input_destination_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->syncOptions($menu, $data['options'] ?? []);
        $this->audit->log('ivr.created', $menu, [], $menu->only(['name_ar', 'name_en', 'timeout', 'max_attempts', 'is_active']), null, $org->id);
        $this->dispatchProvisioning($org, $menu);

        return redirect()->route('company.ivr.index')->with('status', __('company.created'));
    }

    public function update(IvrMenuRequest $request, IvrMenu $ivr)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $ivr);

        $data = $request->validated();
        $old = $ivr->only(['name_ar', 'name_en', 'timeout', 'max_attempts', 'is_active']);

        $ivr->fill([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'greeting_ar' => $data['greeting_ar'] ?? null,
            'greeting_en' => $data['greeting_en'] ?? null,
            'timeout' => $data['timeout'],
            'max_attempts' => $data['max_attempts'],
            'invalid_destination_type' => $data['invalid_destination_type'],
            'invalid_destination_id' => $data['invalid_destination_id'] ?? null,
            'no_input_destination_type' => $data['no_input_destination_type'],
            'no_input_destination_id' => $data['no_input_destination_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ])->save();

        $this->syncOptions($ivr, $data['options'] ?? []);
        $this->audit->log('ivr.updated', $ivr, $old, $ivr->only(['name_ar', 'name_en', 'timeout', 'max_attempts', 'is_active']), null, $org->id);
        $this->dispatchProvisioning($org, $ivr);

        return redirect()->route('company.ivr.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, IvrMenu $ivr)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $ivr);

        $this->audit->log('ivr.deleted', $ivr, ['name_ar' => $ivr->name_ar, 'name_en' => $ivr->name_en], [], null, $org->id);

        $ivr->delete();

        return redirect()->route('company.ivr.index')->with('status', __('company.deleted'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(IvrMenu $menu, array $options): void
    {
        $menu->options()->delete();

        foreach ($options as $option) {
            if (empty($option['digit'])) {
                continue;
            }

            $menu->options()->create([
                'digit' => (string) $option['digit'],
                'destination_type' => $option['destination_type'],
                'destination_id' => $option['destination_id'] ?? null,
            ]);
        }
    }

    private function dispatchProvisioning(Organization $org, IvrMenu $menu): void
    {
        $payload = [
            'organization_id' => $org->id,
            'ivr_menu_id' => $menu->id,
            'name_ar' => $menu->name_ar,
            'name_en' => $menu->name_en,
            'timeout' => $menu->timeout,
            'max_attempts' => $menu->max_attempts,
            'is_active' => $menu->is_active,
            'options' => $menu->options()->get(['digit', 'destination_type', 'destination_id'])->toArray(),
        ];

        $key = 'ivr:'.$menu->id.':'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_ivr', $org->id, $payload, $key);
    }
}
