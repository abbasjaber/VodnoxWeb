<?php

namespace Tests\Feature\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\AuditLog;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\Telephony\GatewayLineRoutingService;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenant;
use Tests\Support\FakeTelephonyGateway;
use Tests\TestCase;

/**
 * The organization workspace for GSM gateways, end to end through HTTP.
 *
 * Verified here:
 *   - the page is unreachable for a plain employee and for a guest;
 *   - a supervisor may *look* but never manage, rotate or read a SIP secret —
 *     being inside the company route group grants nothing by itself;
 *   - the tenant always comes from the session, never from the submitted body;
 *   - a device of another tenant resolves to 404, and a line id that does not
 *     belong to the bound device also resolves to 404;
 *   - free-text fields are stripped of control characters before they can reach
 *     a generated config (CRLF / INI / dialplan injection);
 *   - adding a device never collects — or invents — an inbound destination, so
 *     it can never link another tenant's extension either;
 *   - the secret endpoint is no-store, audited without the secret, and the
 *     secret never appears in any HTML page;
 *   - the super-admin tool is cross-tenant and read-only.
 */
class GatewayWorkspaceTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    private FakeTelephonyGateway $agent;

    private GatewayProvisioningService $gateways;

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

        // No permission seeder runs in the test suite, so the grants are made
        // explicitly — which is exactly what the separation of duties checks.
        $this->grant('organization_admin', [
            'view_gateways', 'manage_gateways', 'view_gateway_secrets', 'rotate_gateway_secrets',
        ]);
        $this->grant('supervisor', ['view_gateways']);

        $this->agent = new FakeTelephonyGateway;
        $this->app->instance(TelephonyGateway::class, $this->agent);

        $this->gateways = app(GatewayProvisioningService::class);
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

    private function extension(Organization $organization, string $number = '1001'): Extension
    {
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

    private function deviceFor(Organization $organization, Extension $destination): GatewayDevice
    {
        // A device is added *without* an inbound destination: routing is owned
        // by the numbers & routing workspace and is attached afterwards.
        $device = $this->gateways->createDevice(
            $organization,
            $this->admin->id,
            ['label' => 'GoIP '.$organization->id, 'model' => 'goip-1'],
            [['label' => 'SIM 1', 'sim_number' => '0770', 'enabled' => true]],
        );

        $this->setDefaultDestination($device->lines()->first(), $destination);

        return $device;
    }

    private function setDefaultDestination(GatewayLine $line, Extension $destination): void
    {
        app(GatewayLineRoutingService::class)->routeFor($line)
            ->forceFill(['default_destination_extension_id' => $destination->id])
            ->save();

        $line->forceFill(['inbound_extension_id' => $destination->id])->save();
    }

    private function ownDevice(): GatewayDevice
    {
        return $this->deviceFor($this->organization, $this->extension($this->organization));
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guest_is_redirected_from_the_gateway_page(): void
    {
        $this->get(route('company.gateways.index'))->assertRedirect(route('login'));
    }

    public function test_a_supervisor_may_look_but_never_manage_or_read_secrets(): void
    {
        $device = $this->ownDevice();
        $line = $device->lines()->first();
        $supervisor = $this->orgUser($this->organization, 'supervisor');

        $this->actingAs($supervisor)->get(route('company.gateways.index'))->assertOk();

        $this->actingAs($supervisor)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP',
                'model' => 'goip-1',
                'lines' => [['label' => 'SIM 1', 'enabled' => true]],
            ])
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->post(route('company.gateways.lines.rotate', [$device, $line]))
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertForbidden();

        // The identity is unchanged by the denied rotation.
        $this->assertSame($line->sip_username, $line->fresh()->sip_username);
    }

    public function test_no_gateway_page_ever_renders_the_sip_password(): void
    {
        $device = $this->ownDevice();
        $line = $device->lines()->first();
        $supervisor = $this->orgUser($this->organization, 'supervisor');

        foreach ([$this->admin, $supervisor] as $user) {
            $this->actingAs($user)
                ->get(route('company.gateways.index'))
                ->assertOk()
                ->assertDontSee($line->sip_password);
        }
    }

    public function test_an_organization_user_cannot_reach_the_super_admin_gateway_tool(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.gateways.index'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Creation & tenancy
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_can_add_a_device_and_the_tenant_comes_from_the_session(): void
    {
        $other = $this->organization();

        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP A',
                'model' => 'goip-1',
                // Spoof attempt: must be ignored, the tenant is the session user's.
                'organization_id' => $other->id,
                'lines' => [[
                    'label' => 'SIM 1',
                    'sim_number' => '07701111111',
                ]],
            ])
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHasNoErrors();

        $device = GatewayDevice::query()->sole();

        $this->assertSame($this->organization->id, $device->organization_id);
        $this->assertSame('goip-1', $device->model);
        $this->assertSame(1, $device->lines()->count());

        $line = $device->lines()->first();

        // Adding a device never asks for (or invents) an inbound destination:
        // the port is created unused and its routing is chosen afterwards from
        // the numbers & routing workspace.
        $this->assertNull($line->inbound_extension_id);
        $this->assertFalse($line->enabled);
        $this->assertNotSame($line->inbound_number, $line->sip_username);
    }

    public function test_control_characters_are_stripped_from_free_text(): void
    {
        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => "GoIP\r\n[context]\nmalicious=1",
                'model' => 'goip-1',
                'network_notes' => "line1\r\nline2",
                'lines' => [[
                    'label' => "SIM\r\n1",
                    'sim_number' => "0770\r\n0",
                ]],
            ])
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHasNoErrors();

        $device = GatewayDevice::query()->sole();
        $line = $device->lines()->first();

        foreach ([$device->label, $device->network_notes, $line->label, $line->sim_number] as $value) {
            $this->assertStringNotContainsString("\r", (string) $value);
            $this->assertStringNotContainsString("\n", (string) $value);
        }
    }

    public function test_adding_a_device_never_links_a_destination_from_another_organization(): void
    {
        $foreign = $this->extension($this->organization(), '1001');

        // The store does not own destinations at all, so a posted one is
        // ignored rather than stored — even a foreign extension id.
        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP',
                'model' => 'goip-1',
                'lines' => [['inbound_extension_id' => $foreign->id, 'enabled' => true]],
            ])
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHasNoErrors();

        $line = GatewayDevice::query()->sole()->lines()->first();

        $this->assertNull($line->inbound_extension_id);
        $this->assertNull($line->route->default_destination_extension_id);
    }

    public function test_an_unapproved_model_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP',
                'model' => 'goip-99',
                'lines' => [['label' => 'SIM 1']],
            ])
            ->assertSessionHasErrors('model');

        $this->assertSame(0, GatewayDevice::query()->count());
    }

    public function test_a_one_port_profile_never_accepts_eight_ports(): void
    {
        // The browser hides and disables the surplus port blocks, but the server
        // must not depend on that: a one-port device can never be created with
        // eight ports, and nothing is written.
        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP',
                'model' => 'goip-1',
                'lines' => array_map(
                    fn (int $index) => ['label' => 'SIM '.($index + 1)],
                    range(0, 7),
                ),
            ])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, GatewayDevice::query()->count());
    }

    public function test_an_eight_port_profile_creates_exactly_eight_independent_ports(): void
    {
        $lines = [];
        for ($index = 0; $index < 8; $index++) {
            $lines[] = [
                'label' => 'SIM '.($index + 1),
                'sim_number' => '0770000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'enabled' => true,
            ];
        }

        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP 8',
                'model' => 'goip-8',
                'lines' => $lines,
            ])
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHasNoErrors();

        $device = GatewayDevice::query()->sole();
        $ports = $device->lines()->orderBy('line_index')->get();

        $this->assertSame(8, $device->max_lines);
        $this->assertSame(8, $ports->count());
        $this->assertSame(range(1, 8), $ports->pluck('line_index')->all());
        $this->assertSame('SIM 1', $ports->first()->label);
        $this->assertSame('SIM 8', $ports->last()->label);
        $this->assertTrue($ports->first()->enabled);

        // Each port keeps its own identity and its own inbound path.
        $this->assertCount(8, $ports->pluck('sip_username')->unique());
        $this->assertCount(8, $ports->pluck('inbound_number')->unique());
    }

    public function test_another_tenants_device_is_not_found_for_every_action(): void
    {
        $otherOrganization = $this->organization();
        $foreignDevice = $this->deviceFor($otherOrganization, $this->extension($otherOrganization));
        $foreignLine = $foreignDevice->lines()->first();

        $this->actingAs($this->admin)
            ->put(route('company.gateways.update', $foreignDevice), ['label' => 'Hacked', 'model' => 'goip-1'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('company.gateways.destroy', $foreignDevice))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('company.gateways.lines.rotate', [$foreignDevice, $foreignLine]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$foreignDevice, $foreignLine]))
            ->assertNotFound();

        $this->assertNotSame('Hacked', $foreignDevice->fresh()->label);
        $this->assertSame($foreignLine->sip_username, $foreignLine->fresh()->sip_username);
    }

    public function test_a_line_that_belongs_to_another_device_is_not_found(): void
    {
        $deviceA = $this->ownDevice();
        $deviceB = $this->deviceFor($this->organization, $this->extension($this->organization, '1002'));

        $lineOfB = $deviceB->lines()->first();

        $this->actingAs($this->admin)
            ->put(route('company.gateways.lines.update', [$deviceA, $lineOfB]), [
                'label' => 'Hijacked',
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$deviceA, $lineOfB]))
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The secret endpoint
    |--------------------------------------------------------------------------
    */

    public function test_the_secret_endpoint_is_no_store_and_audited_without_the_secret(): void
    {
        $device = $this->ownDevice();
        $line = $device->lines()->first();
        $plain = $line->sip_password;

        $response = $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]));

        $response->assertOk();
        $response->assertJsonPath('sip_username', $line->sip_username);
        $response->assertJsonPath('sip_password', $plain);
        $response->assertJsonPath('sip_host', 'sip.gw.test');
        $response->assertJsonPath('sip_transport', 'udp');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $audit = AuditLog::query()->where('action', 'gateway.secret_viewed')->firstOrFail();

        $this->assertSame($line->sip_username, $audit->new_values['sip_username']);
        $this->assertStringNotContainsString($plain, json_encode($audit->new_values));
        $this->assertStringNotContainsString($plain, json_encode($audit->old_values));
    }

    /*
    |--------------------------------------------------------------------------
    | Honest status
    |--------------------------------------------------------------------------
    */

    public function test_an_old_agent_shows_the_service_update_notice(): void
    {
        $this->agent->capabilitiesResponse = [
            'agent_version' => '1.0.0',
            'capabilities' => ['organizations', 'extensions'],
        ];

        $this->actingAs($this->admin)
            ->get(route('company.gateways.index'))
            ->assertOk()
            ->assertSee(__('company.gateway_agent_old'));
    }

    public function test_refresh_reports_an_unavailable_monitoring_source_as_an_error(): void
    {
        $this->agent->statusResponse = ['source' => 'unavailable', 'lines' => []];

        $device = $this->ownDevice();

        $this->actingAs($this->admin)
            ->post(route('company.gateways.refresh', $device))
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHas('error', __('company.gateway_monitoring_unavailable'));

        $this->assertSame('unknown', $device->fresh()->registration_state);
    }

    public function test_a_successful_refresh_uses_the_real_registration_snapshot(): void
    {
        $device = $this->ownDevice();
        $line = $device->lines()->first();

        $this->agent->statusResponse = [
            'source' => 'pjsip',
            'lines' => [$line->sip_username => ['state' => 'registered', 'registered' => true]],
        ];

        $this->actingAs($this->admin)
            ->post(route('company.gateways.refresh', $device))
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHas('status', __('company.gateway_status_refreshed'));

        $this->assertSame('registered', $device->fresh()->registration_state);
    }

    /*
    |--------------------------------------------------------------------------
    | Super-admin surveillance
    |--------------------------------------------------------------------------
    */

    public function test_the_super_admin_tool_is_cross_tenant_read_only_and_secret_free(): void
    {
        $device = $this->ownDevice();
        $line = $device->lines()->first();

        $otherOrganization = $this->organization();
        $foreignDevice = $this->deviceFor($otherOrganization, $this->extension($otherOrganization));

        $superAdmin = $this->superAdmin();

        $response = $this->actingAs($superAdmin)->get(route('admin.gateways.index'));

        $response->assertOk();
        $response->assertSee($device->label);
        $response->assertSee($foreignDevice->label);
        $response->assertDontSee($line->sip_password);

        // There is no management route exposed to the platform team.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.gateways.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.gateways.lines.secret'));
    }
}
