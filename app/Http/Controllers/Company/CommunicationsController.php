<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RoutingScheduleRequest;
use App\Models\IvrMenu;
use App\Models\RoutingSchedule;
use App\Services\AuditService;
use App\Services\Routing\ScheduleMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

class CommunicationsController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScheduleMatcher $matcher,
    ) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $schedules = RoutingSchedule::query()
            ->where('organization_id', $org->id)
            ->with(['days', 'targets.user'])
            ->orderBy('id')
            ->get();

        $users = $org->users()->where('active', true)->orderBy('name')->get();

        $ivrMenus = IvrMenu::query()->where('organization_id', $org->id)->orderBy('id')->get();

        $testSchedule = $schedules->first();

        return view('company.communications', compact('org', 'schedules', 'users', 'ivrMenus', 'testSchedule'));
    }

    public function store(RoutingScheduleRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $schedule = RoutingSchedule::create([
            'organization_id' => $org->id,
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'strategy' => $data['strategy'],
            'is_active' => $request->boolean('is_active'),
            'ring_duration' => $data['ring_duration'],
            'fallback_ring_duration' => $data['fallback_ring_duration'] ?? 20,
            'no_answer_type' => $data['no_answer_type'],
            'no_answer_target_id' => $data['no_answer_target_id'] ?? null,
            'outside_hours_type' => $data['outside_hours_type'],
            'outside_hours_target_id' => $data['outside_hours_target_id'] ?? null,
        ]);

        $this->syncDays($schedule, $data['days']);
        $this->syncTargets($schedule, $data['targets']);

        $this->audit->log('routing_schedule.created', $schedule, [], $schedule->only(['name_ar', 'name_en', 'strategy', 'is_active', 'ring_duration']), null, $org->id);

        return redirect()->route('company.communications.index')->with('status', __('company.created'));
    }

    public function update(RoutingScheduleRequest $request, RoutingSchedule $schedule)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $schedule);

        $data = $request->validated();
        $old = $schedule->only(['name_ar', 'name_en', 'strategy', 'is_active', 'ring_duration']);

        $schedule->fill([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'strategy' => $data['strategy'],
            'is_active' => $request->boolean('is_active'),
            'ring_duration' => $data['ring_duration'],
            'fallback_ring_duration' => $data['fallback_ring_duration'] ?? 20,
            'no_answer_type' => $data['no_answer_type'],
            'no_answer_target_id' => $data['no_answer_target_id'] ?? null,
            'outside_hours_type' => $data['outside_hours_type'],
            'outside_hours_target_id' => $data['outside_hours_target_id'] ?? null,
        ])->save();

        $this->syncDays($schedule, $data['days']);
        $this->syncTargets($schedule, $data['targets']);

        $this->audit->log('routing_schedule.updated', $schedule, $old, $schedule->only(['name_ar', 'name_en', 'strategy', 'is_active', 'ring_duration']), null, $org->id);

        return redirect()->route('company.communications.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, RoutingSchedule $schedule)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $schedule);

        $this->audit->log('routing_schedule.deleted', $schedule, ['name_ar' => $schedule->name_ar, 'name_en' => $schedule->name_en], [], null, $org->id);

        $schedule->delete();

        return redirect()->route('company.communications.index')->with('status', __('company.deleted'));
    }

    /**
     * SIMULATION ONLY: preview which schedule matches a moment and the ordered
     * recipients. Creates no calls, call events or usage rows.
     */
    public function test(Request $request, RoutingSchedule $schedule)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $schedule);

        $timezone = $org->timezone ?: 'Asia/Baghdad';

        try {
            $input = (string) $request->input('datetime', '');
            $moment = $input !== ''
                ? Carbon::parse($input)->setTimezone($timezone)
                : now()->setTimezone($timezone);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => __('company.test_invalid_datetime'),
            ], 422);
        }

        $schedules = RoutingSchedule::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->with(['days', 'targets.user'])
            ->get();

        $matched = $schedules->first(fn (RoutingSchedule $candidate) => $this->matcher->matches($candidate, $moment));

        $targets = $matched
            ? $matched->targets
                ->sortBy('order')
                ->map(fn ($target) => [
                    'id' => $target->user_id,
                    'name' => $target->user?->name,
                    'ring_duration' => $target->ring_duration ?? $matched->ring_duration,
                ])
                ->values()
            : [];

        return response()->json([
            'ok' => true,
            'datetime' => $moment->toIso8601String(),
            'timezone' => $timezone,
            'matched' => $matched ? [
                'id' => $matched->id,
                'name_ar' => $matched->name_ar,
                'name_en' => $matched->name_en,
                'strategy' => $matched->strategy,
                'ring_duration' => $matched->ring_duration,
            ] : null,
            'targets' => $targets,
            'outside_hours_type' => $matched ? null : $schedule->outside_hours_type,
            'messages' => [
                'no_match' => __('company.test_no_match'),
                'targets' => __('company.test_targets'),
                'none' => __('company.test_none'),
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    private function syncDays(RoutingSchedule $schedule, array $days): void
    {
        $schedule->days()->delete();

        foreach ($days as $day) {
            $schedule->days()->create([
                'day' => (int) $day['day'],
                'start_time' => $day['start_time'],
                'end_time' => $day['end_time'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    private function syncTargets(RoutingSchedule $schedule, array $targets): void
    {
        $schedule->targets()->delete();

        foreach (array_values($targets) as $index => $target) {
            $schedule->targets()->create([
                'user_id' => (int) $target['user_id'],
                'order' => $index + 1,
                'ring_duration' => $target['ring_duration'] ?? null,
            ]);
        }
    }
}
