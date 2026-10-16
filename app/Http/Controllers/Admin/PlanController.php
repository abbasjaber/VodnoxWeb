<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Plan;
use App\Services\AuditService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    private const LIMIT_KEYS = ['users', 'extensions', 'concurrent_calls', 'recording_storage', 'phone_numbers'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $plans = Plan::with('limits')->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.plans', [
            'plans' => $plans,
            'limitKeys' => self::LIMIT_KEYS,
        ]);
    }

    public function store(PlanRequest $request)
    {
        $data = $request->validated();
        $limits = $data['limits'] ?? [];
        unset($data['limits']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        $plan = Plan::create($data);
        $this->syncLimits($plan, $limits);

        $this->audit->log('plan.created', $plan, [], $plan->only(['name_ar', 'name_en']));

        return back()->with('status', __('admin.flash_created'));
    }

    public function update(PlanRequest $request, Plan $plan)
    {
        $data = $request->validated();
        $limits = $data['limits'] ?? [];
        unset($data['limits']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        $plan->update($data);
        $this->syncLimits($plan, $limits);

        $this->audit->log('plan.updated', $plan, [], $plan->only(['name_ar', 'name_en']));

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();

        $this->audit->log('plan.deleted', null, ['name' => $plan->name_en], []);

        return back()->with('status', __('admin.flash_deleted'));
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function syncLimits(Plan $plan, array $limits): void
    {
        foreach (self::LIMIT_KEYS as $key) {
            $value = $limits[$key] ?? null;

            if ($value === null || $value === '') {
                $plan->limits()->where('key', $key)->delete();
                continue;
            }

            $plan->limits()->updateOrCreate(['key' => $key], ['value' => (int) $value]);
        }
    }
}
