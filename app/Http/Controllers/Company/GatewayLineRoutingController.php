<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\GatewayLineRoutingRequest;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Services\AuditService;
use App\Services\Telephony\GatewayLineRoutingService;
use App\Services\Telephony\GatewayProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The numbers & routing workspace: inbound routing of ONE gateway port.
 *
 * This is the only place that owns "where does a call arriving on this SIM go".
 * The very same rows are (a) resolved here for the administrator's preview and
 * (b) compiled by the Asterisk agent into the call-time dialplan, so the preview
 * can never disagree with the real call.
 *
 * Destinations are employee extensions of the same organization only. Ring
 * groups / IVR / queues are deliberately not selectable: the agent does not
 * implement them for gateway lines yet, and offering them would store a value
 * that fails at call time.
 */
class GatewayLineRoutingController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly GatewayProvisioningService $gateways,
        private readonly GatewayLineRoutingService $routing,
    ) {}

    public function edit(Request $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'view_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $route = $this->routing->routeFor($line);

        return view('company.gateway-line-routing', [
            'org' => $org,
            'gateway' => $gateway,
            'line' => $line,
            'route' => $route->load([
                'businessHours', 'weekendDays', 'dateExceptions.destination',
                'defaultDestination', 'outsideHoursDestination', 'weekendDestination',
                'fallbackDestination', 'temporaryForwardDestination',
            ]),
            'destinations' => $this->destinations($org),
            'timezone' => $route->timezone(),
            'canManage' => $request->user()->hasPermission('manage_gateways'),
            'supportsRouting' => $this->gateways->agentSupportsRouting(),
        ]);
    }

    public function update(GatewayLineRoutingRequest $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $data = $request->validated();

        $windows = $this->windows($data);
        $weekendDays = array_values(array_unique(array_map('intval', $data['weekend_days'] ?? [])));
        $exceptions = $this->exceptions($data);

        // Overlaps and inverted ranges are refused explicitly instead of being
        // resolved by an implicit "whichever wins" rule.
        $this->routing->validateBusinessHours($windows);
        $this->routing->validateExceptions($exceptions);

        foreach (['default_destination_extension_id', 'outside_hours_destination_extension_id',
            'weekend_destination_extension_id', 'fallback_destination_extension_id',
            'temporary_forward_extension_id'] as $field) {
            $this->routing->validateDestination($data[$field] ?? null, $org->getKey(), $field);
        }

        $route = $this->routing->routeFor($line);
        $old = $route->only([
            'default_destination_extension_id', 'outside_hours_destination_extension_id',
            'weekend_destination_extension_id', 'fallback_destination_extension_id',
            'temporary_forward_enabled', 'temporary_forward_extension_id',
        ]);

        DB::transaction(function () use ($route, $line, $data, $windows, $weekendDays, $exceptions) {
            $route->fill([
                'default_destination_extension_id' => $data['default_destination_extension_id'] ?? null,
                'outside_hours_destination_extension_id' => $data['outside_hours_destination_extension_id'] ?? null,
                'weekend_destination_extension_id' => $data['weekend_destination_extension_id'] ?? null,
                'fallback_destination_extension_id' => $data['fallback_destination_extension_id'] ?? null,
                'temporary_forward_enabled' => (bool) ($data['temporary_forward_enabled'] ?? false),
                'temporary_forward_extension_id' => $data['temporary_forward_extension_id'] ?? null,
            ])->save();

            $route->businessHours()->delete();
            foreach ($windows as $window) {
                $route->businessHours()->create($window);
            }

            $route->weekendDays()->delete();
            foreach ($weekendDays as $day) {
                $route->weekendDays()->create(['day' => $day]);
            }

            $route->dateExceptions()->delete();
            foreach ($exceptions as $exception) {
                $route->dateExceptions()->create($exception);
            }

            // Keep the legacy single destination in step with the routing
            // default, so anything still reading `gateway_lines` agrees.
            $line->forceFill(['inbound_extension_id' => $route->default_destination_extension_id])->save();
        });

        // Recompiling the routing must never rotate the SIP secret or force a
        // re-registration; only the dialplan of this line changes.
        $this->gateways->syncLine($line->refresh());

        $this->audit->log('gateway.line.routing_updated', $line, $old, $route->only(array_keys($old)), null, $org->id);

        return redirect()
            ->route('company.numbers.routing.edit', ['gateway' => $gateway->getKey(), 'line' => $line->getKey()])
            ->with('status', __('company.gateway_routing_saved'));
    }

    /**
     * Resolve the destination that WOULD apply at a chosen moment.
     *
     * This is a preview, not a call: it runs the exact same matcher the dialplan
     * is generated from, so what the administrator sees is what the port does.
     */
    public function preview(Request $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'view_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $data = $request->validate([
            'preview_date' => ['required', 'date_format:Y-m-d'],
            'preview_time' => ['required', 'date_format:H:i'],
        ]);

        $route = $this->routing->routeFor($line);
        $timezone = $route->timezone();
        $moment = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['preview_date'].' '.$data['preview_time'], $timezone);

        $result = $this->routing->resolve($route, $moment);

        return redirect()
            ->route('company.numbers.routing.edit', ['gateway' => $gateway->getKey(), 'line' => $line->getKey()])
            ->with('routing_preview', [
                'at' => $moment->format('Y-m-d H:i'),
                'timezone' => $timezone,
                'rule' => $result['rule'],
                'matched_rule' => $result['matched_rule'],
                'destination' => $result['destination']?->label,
                'destination_extension' => $result['destination']?->extension,
                'reason' => $result['reason'],
                'exception_label' => $result['exception_label'],
                'in_business_hours' => $result['in_business_hours'],
            ]);
    }

    /**
     * Active employee extensions of this organization only.
     */
    private function destinations(\App\Models\Organization $org)
    {
        return $org->extensions()
            ->where('status', 'active')
            ->whereNotNull('sip_username')
            ->orderBy('extension')
            ->get(['id', 'extension', 'label', 'user_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{day: int, start_time: string, end_time: string}>
     */
    private function windows(array $data): array
    {
        $windows = [];

        foreach ((array) ($data['business_hours'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['day'], $row['start'], $row['end'])) {
                continue;
            }

            $windows[] = [
                'day' => (int) $row['day'],
                'start_time' => (string) $row['start'],
                'end_time' => (string) $row['end'],
            ];
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function exceptions(array $data): array
    {
        $exceptions = [];

        foreach ((array) ($data['exceptions'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['starts_on'], $row['ends_on'])) {
                continue;
            }

            $exceptions[] = [
                'starts_on' => (string) $row['starts_on'],
                'ends_on' => (string) $row['ends_on'],
                'destination_extension_id' => $row['destination_extension_id'] ?? null,
                'label' => $row['label'] ?? null,
            ];
        }

        return $exceptions;
    }

    private function authorizeLine(GatewayDevice $gateway, GatewayLine $line): void
    {
        abort_unless((int) $line->gateway_device_id === (int) $gateway->getKey(), 404);
    }
}
