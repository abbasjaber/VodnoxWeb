<?php

namespace Tests\Feature\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\GatewayLineRoute;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\Telephony\GatewayLineRoutingService;
use App\Services\Telephony\GatewayProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenant;
use Tests\Support\FakeTelephonyGateway;
use Tests\TestCase;
use Vodnox\Telephony\Agent;

require_once dirname(__DIR__, 3).'/deploy/telephony/provisioning/src/Agent.php';

/**
 * The numbers & routing workspace, end to end.
 *
 * The very same rows are resolved here for the preview and compiled by the
 * agent into the call-time dialplan, so these tests pin the *decision*, not a
 * rendering:
 *   - the published priority order (temporary forward > dated exception >
 *     weekend > business hours / outside hours > default > fallback > refuse);
 *   - a window that crosses midnight and a window whose end is exclusive;
 *   - the organization timezone decides, never the server's or the browser's;
 *   - an inclusive dated exception;
 *   - a missing/disabled destination refuses the call with a readable reason
 *     instead of falling through to another tenant or an outbound path;
 *   - that refusal happens for a *matched* rule too: an unusable temporary
 *     forward or dated exception keeps its priority and is refused, never
 *     silently downgraded to a weekly rule (verified against the real
 *     `Agent::routingDialplan()` output, not a copy of its rules);
 *   - the generated dialplan converts the clock with the organization timezone
 *     explicitly, so a media server running on UTC decides the same as the
 *     Baghdad preview;
 *   - another tenant's extension is refused;
 *   - overlapping rules are refused rather than resolved by "whichever wins";
 *   - saving routing re-provisions the port without rotating its SIP secret;
 *   - the preview agrees with the real decision, and the workspace is not
 *     reachable by a guest or a plain employee.
 */
class GatewayLineRoutingTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    private FakeTelephonyGateway $agent;

    private GatewayProvisioningService $gateways;

    private GatewayLineRoutingService $routing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.telephony.gateway_sip_host' => 'sip.gw.test',
            'integrations.telephony.gateway_sip_port' => 5060,
            'integrations.telephony.gateway_sip_transport' => 'udp',
            'integrations.telephony.gateway_realm' => 'gw.test',
            'integrations.telephony.gateway_inbound_start' => 5000,
        ]);

        Queue::fake();

        $this->organization = $this->organization();
        $this->admin = $this->orgAdmin($this->organization);

        $this->grant('organization_admin', ['view_gateways', 'manage_gateways']);
        $this->grant('supervisor', ['view_gateways']);

        $this->agent = new FakeTelephonyGateway;
        $this->app->instance(TelephonyGateway::class, $this->agent);

        $this->gateways = app(GatewayProvisioningService::class);
        $this->routing = app(GatewayLineRoutingService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function grant(string $roleName, array $permissions): void
    {
        $role = $this->role($roleName);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label_ar' => $name, 'label_en' => $name],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    private function extension(string $number = '1001', ?Organization $organization = null): Extension
    {
        $organization ??= $this->organization;
        $user = $this->orgUser($organization, 'agent', ['extension' => $number]);

        return Extension::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'extension' => $number,
            'label' => $user->name,
            'status' => 'active',
            'provisioning_status' => 'synced',
            'sip_username' => 'vdx'.$organization->id.'_'.Str::lower(Str::random(10)),
            'sip_password' => 'sip-'.Str::random(24),
            'sip_domain' => 'pbx.vodnox.com',
        ]);
    }

    private function device(string $model = 'goip-1', ?Organization $organization = null): GatewayDevice
    {
        $organization ??= $this->organization;
        $admin = $organization->id === $this->organization->id
            ? $this->admin->id
            : $this->orgAdmin($organization)->id;

        return $this->gateways->createDevice(
            $organization,
            $admin,
            ['label' => 'GoIP '.$organization->id, 'model' => $model],
            [],
        );
    }

    private function firstLine(GatewayDevice $device): GatewayLine
    {
        return $device->lines()->orderBy('line_index')->firstOrFail();
    }

    /**
     * A fresh routing row, so relations are never read from a stale instance.
     */
    private function route(GatewayLine $line): GatewayLineRoute
    {
        return $this->routing->routeFor($line->fresh());
    }

    private function routeLineTo(GatewayLine $line, ?Extension $destination): GatewayLineRoute
    {
        $route = $this->route($line);
        $route->forceFill(['default_destination_extension_id' => $destination?->id])->save();

        return $route;
    }

    /**
     * @param  array<int, array{day:int,start:string,end:string}>  $windows
     */
    private function withWindows(GatewayLineRoute $route, array $windows): void
    {
        $route->businessHours()->delete();

        foreach ($windows as $window) {
            $route->businessHours()->create([
                'day' => $window['day'],
                'start_time' => $window['start'],
                'end_time' => $window['end'],
            ]);
        }
    }

    /**
     * Compile a routing payload exactly as the media-server agent does, so the
     * preview can be compared with the dialplan that really decides the call.
     *
     * The real Agent class is loaded and driven (not a re-implementation of its
     * rules): a copy of the logic here would prove nothing about production.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function compileDialplan(array $payload): array
    {
        $agent = new Agent([
            'state_dir' => sys_get_temp_dir().'/vodnox-agent-compile',
            'asterisk' => [
                'include_dir' => sys_get_temp_dir().'/vodnox-agent-compile/generated',
                'dialplan_include_dir' => sys_get_temp_dir().'/vodnox-agent-compile/dialplan',
            ],
        ]);

        $normalize = new \ReflectionMethod($agent, 'normalizeRouting');
        $routing = $normalize->invoke($agent, $payload);

        $this->assertIsArray($routing, 'the payload must survive the agent validation');

        $compile = new \ReflectionMethod($agent, 'routingDialplan');

        /** @var array<int, string> $lines */
        $lines = $compile->invoke($agent, $routing, 30);

        return $lines;
    }

    /**
     * The label => target table of the compiled dialplan, as dialled.
     *
     * @param  array<int, string>  $lines
     * @return array<string, string>
     */
    private function branchTargets(array $lines): array
    {
        $targets = [];

        foreach ($lines as $line) {
            if (preg_match('/^ same => n\((vdx_[a-z0-9_]+)\),Set\(VDX_TARGET=(.*)\)$/', $line, $matches)) {
                $targets[$matches[1]] = $matches[2];
            }
        }

        return $targets;
    }

    /**
     * The position of the first line starting with $prefix, so the compiled
     * priority order can be asserted.
     *
     * @param  array<int, string>  $lines
     */
    private function indexOfPrefix(array $lines, string $prefix): int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                return $index;
            }
        }

        $this->fail('No compiled dialplan line starts with: '.$prefix);
    }

    /*
    |--------------------------------------------------------------------------
    | Priority: the published, fixed order
    |--------------------------------------------------------------------------
    */

    public function test_the_default_destination_decides_when_no_weekly_rule_matches(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $destination = $this->extension('1100');

        $route = $this->routeLineTo($line, $destination);

        $result = $this->routing->resolve($route, CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));

        $this->assertSame('default', $result['rule']);
        $this->assertSame($destination->id, $result['destination_extension_id']);
    }

    public function test_business_hours_select_the_in_hours_destination_and_outside_hours_the_other(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['outside_hours_destination_extension_id' => $outside->id])->save();
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);

        $fresh = $this->route($line);

        // Sunday 10:00 — inside the window.
        $inside = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));
        $this->assertSame('business_hours', $inside['rule']);
        $this->assertSame($inHours->id, $inside['destination_extension_id']);

        // Sunday 18:00 — after the window.
        $after = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 18:00', 'Asia/Baghdad'));
        $this->assertSame('outside_hours', $after['rule']);
        $this->assertSame($outside->id, $after['destination_extension_id']);
    }

    public function test_the_end_of_a_window_is_exclusive(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['outside_hours_destination_extension_id' => $outside->id])->save();
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);

        $fresh = $this->route($line);

        $atStart = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 09:00', 'Asia/Baghdad'));
        $this->assertSame('business_hours', $atStart['rule']);

        $atEnd = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 17:00', 'Asia/Baghdad'));
        $this->assertSame('outside_hours', $atEnd['rule']);
    }

    public function test_a_window_that_crosses_midnight_covers_the_next_day(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['outside_hours_destination_extension_id' => $outside->id])->save();
        // Saturday 22:00 until Sunday 02:00.
        $this->withWindows($route, [['day' => 6, 'start' => '22:00', 'end' => '02:00']]);

        $fresh = $this->route($line);

        // Sunday 01:00 is still covered by the Saturday window.
        $night = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 01:00', 'Asia/Baghdad'));
        $this->assertSame('business_hours', $night['rule']);
        $this->assertSame($inHours->id, $night['destination_extension_id']);

        // Sunday 02:00 is where the window ends (exclusive).
        $ended = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 02:00', 'Asia/Baghdad'));
        $this->assertSame('outside_hours', $ended['rule']);
    }

    public function test_a_weekend_day_beats_a_business_hours_window(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $weekend = $this->extension('1102');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['weekend_destination_extension_id' => $weekend->id])->save();
        // Friday is a holiday *and* has a nominal window: the holiday wins.
        $route->weekendDays()->create(['day' => 5]);
        $this->withWindows($route, [['day' => 5, 'start' => '09:00', 'end' => '17:00']]);

        $fresh = $this->route($line);

        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-02 10:00', 'Asia/Baghdad'));

        $this->assertSame('weekend', $result['rule']);
        $this->assertSame($weekend->id, $result['destination_extension_id']);
    }

    public function test_a_dated_exception_beats_the_weekly_rules_and_is_inclusive(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $weekend = $this->extension('1102');
        $exceptionDestination = $this->extension('1103');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['weekend_destination_extension_id' => $weekend->id])->save();
        $route->weekendDays()->create(['day' => 5]);
        // 2026-01-02 (Friday) .. 2026-01-05 (Monday), both inclusive.
        $route->dateExceptions()->create([
            'starts_on' => '2026-01-02',
            'ends_on' => '2026-01-05',
            'destination_extension_id' => $exceptionDestination->id,
            'label' => 'Cover',
        ]);

        $fresh = $this->route($line);

        $lastDay = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Baghdad'));
        $this->assertSame('date_exception', $lastDay['rule']);
        $this->assertSame($exceptionDestination->id, $lastDay['destination_extension_id']);

        // One day after the inclusive end the weekly rules decide again.
        $after = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-06 10:00', 'Asia/Baghdad'));
        $this->assertSame('default', $after['rule']);
    }

    public function test_a_temporary_forward_beats_every_other_rule(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $temp = $this->extension('1104');
        $exceptionDestination = $this->extension('1103');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill([
            'temporary_forward_enabled' => true,
            'temporary_forward_extension_id' => $temp->id,
        ])->save();
        $route->dateExceptions()->create([
            'starts_on' => '2026-01-02',
            'ends_on' => '2026-01-05',
            'destination_extension_id' => $exceptionDestination->id,
        ]);

        $fresh = $this->route($line);

        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Baghdad'));

        $this->assertSame('temporary_forward', $result['rule']);
        $this->assertSame($temp->id, $result['destination_extension_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    */

    public function test_the_organization_timezone_decides_not_the_server(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $inHours = $this->extension('1100');

        $route = $this->routeLineTo($line, $inHours);
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);

        $fresh = $this->route($line);

        // 07:00 UTC is 10:00 in Baghdad (UTC+3): inside the Sunday window.
        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 07:00', 'UTC'));

        $this->assertSame('Asia/Baghdad', $result['timezone']);
        $this->assertSame('business_hours', $result['rule']);
    }

    /*
    |--------------------------------------------------------------------------
    | Failures: never guessed, never another tenant
    |--------------------------------------------------------------------------
    */

    public function test_a_disabled_destination_uses_the_configured_fallback(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $fallback = $this->extension('1110');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['fallback_destination_extension_id' => $fallback->id])->save();

        $inHours->forceFill(['status' => 'inactive'])->save();

        $result = $this->routing->resolve($this->route($line), CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));

        $this->assertSame('fallback', $result['rule']);
        $this->assertSame($fallback->id, $result['destination_extension_id']);
    }

    public function test_a_missing_destination_refuses_the_call_with_a_readable_reason(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        // No default at all: the port is provisioned but the call is refused.
        $result = $this->routing->resolve($this->route($line), CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));

        $this->assertSame('none', $result['rule']);
        $this->assertNull($result['destination']);
        $this->assertSame(GatewayLineRoutingService::REASON_NO_DESTINATION, $result['reason']);

        // A destination that became unusable is refused with its own reason.
        $destination = $this->extension('1100');
        $route = $this->routeLineTo($line, $destination);
        $destination->forceFill(['status' => 'inactive'])->save();

        $refused = $this->routing->resolve($this->route($line), CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));

        $this->assertSame('none', $refused['rule']);
        $this->assertSame(GatewayLineRoutingService::REASON_DESTINATION_UNAVAILABLE, $refused['reason']);
    }

    /*
    |--------------------------------------------------------------------------
    | Ports stay independent
    |--------------------------------------------------------------------------
    */

    public function test_each_port_keeps_its_own_identity_number_and_destination(): void
    {
        $device = $this->device('goip-8');
        $lines = $device->lines()->orderBy('line_index')->get();

        $this->assertCount(8, $lines);
        // No port is assumed to carry a SIM.
        $this->assertFalse($lines->contains(fn (GatewayLine $line) => $line->enabled));

        $destinations = [$this->extension('1100'), $this->extension('1101'), $this->extension('1102')];
        $used = $lines->take(3)->values();

        foreach ($used as $index => $line) {
            $this->gateways->setLineEnabled($line, true);
            $this->routeLineTo($line, $destinations[$index]);
        }

        // Distinct SIP identities and distinct inbound paths per port.
        $this->assertCount(3, array_unique($used->pluck('sip_username')->all()));
        $this->assertCount(3, array_unique($used->pluck('inbound_number')->all()));

        foreach ($lines as $line) {
            $this->assertSame('vodnox_gw_'.$this->organization->id.'_'.$line->id, $line->inboundContext());
        }

        // The third port's payload carries its own destination, not a sibling's.
        $payload = $this->gateways->linePayload($used[2]->fresh(), 1, true);

        $this->assertSame($destinations[2]->sip_username, $payload['routing']['in_hours_destination_username']);
        $this->assertNotSame($destinations[0]->sip_username, $payload['routing']['in_hours_destination_username']);

        // Disabling one port leaves the others alone.
        $this->gateways->setLineEnabled($used[1]->fresh(), false);

        $this->assertFalse($used[1]->fresh()->enabled);
        $this->assertTrue($used[0]->fresh()->enabled);
        $this->assertTrue($used[2]->fresh()->enabled);
        $this->assertFalse($lines[5]->fresh()->enabled);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP: validation, secret stability and access control
    |--------------------------------------------------------------------------
    */

    public function test_saving_routing_re_provisions_without_rotating_the_secret(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $username = $line->sip_username;
        $password = $line->sip_password;

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');

        $this->actingAs($this->admin)
            ->put(route('company.numbers.routing.update', ['gateway' => $device, 'line' => $line]), [
                'default_destination_extension_id' => $inHours->id,
                'outside_hours_destination_extension_id' => $outside->id,
                'business_hours' => [['day' => 0, 'start' => '09:00', 'end' => '17:00']],
                'weekend_days' => [],
                'exceptions' => [],
            ])
            ->assertRedirect(route('company.numbers.routing.edit', ['gateway' => $device, 'line' => $line]))
            ->assertSessionHas('status', __('company.gateway_routing_saved'));

        $line->refresh();

        // Recompiling the routing never touches the SIP identity or its secret.
        $this->assertSame($username, $line->sip_username);
        $this->assertSame($password, $line->sip_password);

        // The full routing rule set is pushed to the agent.
        Queue::assertPushed(
            \App\Jobs\ProvisionTelephonyJob::class,
            fn ($job) => $job->gatewayLineId === $line->id
                && $job->payload['routing']['in_hours_destination_username'] === $inHours->sip_username
                && $job->payload['routing']['outside_hours_destination_username'] === $outside->sip_username
                && count($job->payload['routing']['business_hours']) === 1,
        );
    }

    public function test_overlapping_business_hours_are_refused(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $this->actingAs($this->admin)
            ->put(route('company.numbers.routing.update', ['gateway' => $device, 'line' => $line]), [
                'business_hours' => [
                    ['day' => 0, 'start' => '09:00', 'end' => '17:00'],
                    ['day' => 0, 'start' => '12:00', 'end' => '15:00'],
                ],
            ])
            ->assertSessionHasErrors('business_hours.1.day');
    }

    public function test_overlapping_exceptions_are_refused(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $destination = $this->extension('1100');

        $this->actingAs($this->admin)
            ->put(route('company.numbers.routing.update', ['gateway' => $device, 'line' => $line]), [
                'default_destination_extension_id' => $destination->id,
                'exceptions' => [
                    ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-10'],
                    ['starts_on' => '2026-01-05', 'ends_on' => '2026-01-15'],
                ],
            ])
            ->assertSessionHasErrors('exceptions.1.starts_on');
    }

    public function test_a_destination_from_another_organization_is_refused(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        // An active extension that lives in a *different* tenant.
        $other = $this->organization();
        $foreign = $this->extension('1100', $other);

        $this->actingAs($this->admin)
            ->put(route('company.numbers.routing.update', ['gateway' => $device, 'line' => $line]), [
                'default_destination_extension_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('default_destination_extension_id');

        $this->assertNull($this->route($line)->default_destination_extension_id);
    }

    public function test_the_preview_agrees_with_the_real_decision(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');

        $this->actingAs($this->admin)
            ->put(route('company.numbers.routing.update', ['gateway' => $device, 'line' => $line]), [
                'default_destination_extension_id' => $inHours->id,
                'outside_hours_destination_extension_id' => $outside->id,
                'business_hours' => [['day' => 0, 'start' => '09:00', 'end' => '17:00']],
            ])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)
            ->post(route('company.numbers.routing.preview', ['gateway' => $device, 'line' => $line]), [
                'preview_date' => '2026-01-04',
                'preview_time' => '18:00',
            ]);

        $response->assertRedirect(route('company.numbers.routing.edit', ['gateway' => $device, 'line' => $line]));
        $response->assertSessionHas('routing_preview');

        $expected = $this->routing->resolve(
            $this->route($line),
            CarbonImmutable::parse('2026-01-04 18:00', 'Asia/Baghdad'),
        );

        $preview = $response->getSession()->get('routing_preview');

        $this->assertSame($expected['rule'], $preview['rule']);
        $this->assertSame($outside->extension, $preview['destination_extension']);
    }

    public function test_the_routing_workspace_is_not_reachable_by_a_guest_or_a_plain_employee(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $route = ['gateway' => $device, 'line' => $line];

        $this->get(route('company.numbers.routing.edit', $route))->assertRedirect(route('login'));

        $employee = $this->orgUser($this->organization, 'agent');
        $this->actingAs($employee)->get(route('company.numbers.routing.edit', $route))->assertForbidden();
        $this->actingAs($employee)->put(route('company.numbers.routing.update', $route), [])->assertForbidden();
    }

    public function test_a_supervisor_may_preview_but_never_save_routing(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $route = ['gateway' => $device, 'line' => $line];
        $supervisor = $this->orgUser($this->organization, 'supervisor');

        $this->actingAs($supervisor)->get(route('company.numbers.routing.edit', $route))->assertOk();

        $this->actingAs($supervisor)
            ->put(route('company.numbers.routing.update', $route), ['weekend_days' => [5]])
            ->assertForbidden();
    }

    public function test_another_tenants_port_routing_is_not_found(): void
    {
        $other = $this->organization();
        $foreignDevice = $this->device('goip-1', $other);
        $foreignLine = $this->firstLine($foreignDevice);

        $this->actingAs($this->admin)
            ->get(route('company.numbers.routing.edit', ['gateway' => $foreignDevice, 'line' => $foreignLine]))
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The preview and the generated dialplan cannot disagree
    |--------------------------------------------------------------------------
    */

    public function test_an_unusable_temporary_forward_is_refused_and_never_downgraded(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $temp = $this->extension('1104');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill([
            'temporary_forward_enabled' => true,
            'temporary_forward_extension_id' => $temp->id,
        ])->save();
        // A window and a usable default exist: without the fix the call would be
        // silently downgraded to them instead of being refused.
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);
        $temp->forceFill(['status' => 'inactive'])->save();

        $fresh = $this->route($line);

        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));
        $this->assertSame('none', $result['rule']);
        $this->assertSame(GatewayLineRoutingService::REASON_DESTINATION_UNAVAILABLE, $result['reason']);

        $payload = $this->routing->payload($fresh);

        // The forward is still ON: it keeps priority even without a target.
        $this->assertNotNull($payload['temporary_forward']);
        $this->assertNull($payload['temporary_forward']['destination_username']);

        $lines = $this->compileDialplan($payload);

        $this->assertContains(' same => n,Goto(vdx_temporary)', $lines);
        // Nothing lower is even compiled, so nothing can be reached.
        $this->assertNotContains(' same => n,Goto(vdx_default)', $lines);

        $targets = $this->branchTargets($lines);
        $this->assertSame('', $targets['vdx_temporary']);
        $this->assertContains(' same => n(vdx_dial),GotoIf($["${VDX_TARGET}" = ""]?vdx_refuse)', $lines);
        $this->assertContains(' same => n,Hangup(480)', $lines);
    }

    public function test_an_unusable_dated_exception_is_refused_and_never_downgraded(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $outside = $this->extension('1101');
        $holiday = $this->extension('1102');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['outside_hours_destination_extension_id' => $outside->id])->save();
        $route->dateExceptions()->create([
            'starts_on' => '2026-01-04',
            'ends_on' => '2026-01-04',
            'destination_extension_id' => $holiday->id,
        ]);
        // Sunday has a working window too, so a downgrade would be observable.
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);
        $holiday->forceFill(['status' => 'inactive'])->save();

        $fresh = $this->route($line);

        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));
        $this->assertSame('date_exception', $result['matched_rule']);
        $this->assertSame('none', $result['rule']);
        $this->assertSame(GatewayLineRoutingService::REASON_DESTINATION_UNAVAILABLE, $result['reason']);

        $payload = $this->routing->payload($fresh);

        // Kept, not dropped.
        $this->assertCount(1, $payload['exceptions']);
        $this->assertNull($payload['exceptions'][0]['destination_username']);

        $lines = $this->compileDialplan($payload);

        $exception = $this->indexOfPrefix($lines, ' same => n,GotoIf($[${VDX_D} >= 20260104');
        $hours = $this->indexOfPrefix($lines, ' same => n,GotoIf($[(');

        // The exception still wins over the weekly window...
        $this->assertLessThan($hours, $exception);

        $targets = $this->branchTargets($lines);

        // ...and refuses, while the in-hours rule it shadows stays dialable for
        // every other day of the year.
        $this->assertSame('', $targets['vdx_exception_0']);
        $this->assertSame($inHours->sip_username, $targets['vdx_in_hours']);
    }

    public function test_a_matched_rule_without_a_destination_uses_the_fallback_before_refusing(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);

        $inHours = $this->extension('1100');
        $holiday = $this->extension('1102');
        $fallback = $this->extension('1110');

        $route = $this->routeLineTo($line, $inHours);
        $route->forceFill(['fallback_destination_extension_id' => $fallback->id])->save();
        $route->dateExceptions()->create([
            'starts_on' => '2026-01-04',
            'ends_on' => '2026-01-04',
            'destination_extension_id' => $holiday->id,
        ]);
        $holiday->forceFill(['status' => 'inactive'])->save();

        $fresh = $this->route($line);

        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 10:00', 'Asia/Baghdad'));
        $this->assertSame('date_exception', $result['matched_rule']);
        $this->assertSame('fallback', $result['rule']);
        $this->assertSame($fallback->id, $result['destination_extension_id']);

        $lines = $this->compileDialplan($this->routing->payload($fresh));

        // The compiled exception dials the fallback, exactly like the preview.
        $this->assertSame($fallback->sip_username, $this->branchTargets($lines)['vdx_exception_0']);
    }

    public function test_the_generated_dialplan_converts_with_the_organization_timezone(): void
    {
        $device = $this->device();
        $line = $this->firstLine($device);
        $inHours = $this->extension('1100');

        $route = $this->routeLineTo($line, $inHours);
        $this->withWindows($route, [['day' => 0, 'start' => '09:00', 'end' => '17:00']]);

        $fresh = $this->route($line);

        // 07:00 UTC is 10:00 in Baghdad: inside the Sunday window.
        $result = $this->routing->resolve($fresh, CarbonImmutable::parse('2026-01-04 07:00', 'UTC'));
        $this->assertSame('business_hours', $result['rule']);
        $this->assertSame('Asia/Baghdad', $result['timezone']);
        $this->assertSame(0, $result['weekday']);

        // The media server's own PHP runs on UTC while the organization is on
        // Baghdad — the generated file must not depend on the server zone.
        $server = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $lines = $this->compileDialplan($this->routing->payload($fresh));
        } finally {
            date_default_timezone_set($server);
        }

        // One sample of the clock, converted with the organization zone.
        $this->assertContains(' same => n,Set(VDX_NOW=${EPOCH})', $lines);
        $this->assertContains(' same => n,Set(VDX_TZ=Asia/Baghdad)', $lines);
        $this->assertContains(' same => n,Set(VDX_W=${STRFTIME(${VDX_NOW},${VDX_TZ},%w)})', $lines);
        $this->assertContains(' same => n,Set(VDX_T=${STRFTIME(${VDX_NOW},${VDX_TZ},%H%M)})', $lines);
        $this->assertContains(' same => n,Set(VDX_D=${STRFTIME(${VDX_NOW},${VDX_TZ},%Y%m%d)})', $lines);

        // No lookup may leave the zone empty (which would silently use the
        // server zone) or re-read the clock on its own.
        foreach ($lines as $compiled) {
            $this->assertStringNotContainsString('STRFTIME(${EPOCH},,', $compiled);
            $this->assertStringNotContainsString('STRFTIME(${VDX_NOW},,%', $compiled);
        }

        // The compiled window is the same Sunday 09:00-17:00 the preview used.
        $this->assertContains(' same => n,GotoIf($[(${VDX_W} == 0 & ${VDX_T} >= 0900 & ${VDX_T} < 1700)]?vdx_in_hours)', $lines);
        $this->assertSame($inHours->sip_username, $this->branchTargets($lines)['vdx_in_hours']);
    }
}
