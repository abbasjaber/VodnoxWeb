<?php

namespace Tests\Feature\Telephony;

use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telephony\TelephonyProvisioningService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * The per-employee telephony bootstrap endpoint.
 *
 * What is verified here:
 *   - the endpoint is authenticated (guest/tenant-less super admin are refused);
 *   - the state machine stays honest (unprovisioned / pending / failed /
 *     disabled / ready) instead of reporting "ready" for a stale asset;
 *   - a ready employee receives their OWN credentials only, never a
 *     colleague's, and the response is never cacheable;
 *   - the directory lists active colleagues of the same organization only.
 */
class TelephonyCredentialsTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->agent = $this->orgUser($this->organization, 'agent', ['extension' => '1001']);
    }

    private function extension(User $user, array $attributes = []): Extension
    {
        return Extension::create(array_merge([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'extension' => $user->extension,
            'label' => $user->name,
            'status' => 'active',
            'provisioning_status' => 'synced',
            'sip_username' => 'vdx'.$user->organization_id.'_'.strtolower(str()->random(12)),
            'sip_password' => 'sip-secret-'.str()->random(8),
            'sip_domain' => 'pbx.vodnox.com',
        ], $attributes));
    }

    private function fetch(User $user)
    {
        return $this->actingAs($user)->getJson(route('user.telephony.credentials'));
    }

    /*
    |--------------------------------------------------------------------------
    | Access rules
    |--------------------------------------------------------------------------
    */

    public function test_guest_is_redirected(): void
    {
        $this->get(route('user.telephony.credentials'))->assertRedirect(route('login'));
    }

    public function test_super_admin_without_a_tenant_is_forbidden(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson(route('user.telephony.credentials'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | State machine
    |--------------------------------------------------------------------------
    */

    public function test_an_employee_without_an_asset_is_reported_as_unprovisioned(): void
    {
        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('unprovisioned', $response->json('state'));
        $this->assertNull($response->json('sip'));
    }

    public function test_a_pending_asset_never_exposes_credentials(): void
    {
        $this->extension($this->agent, ['provisioning_status' => 'pending']);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('pending', $response->json('state'));
        $this->assertNull($response->json('sip'));
    }

    public function test_a_failed_asset_surfaces_the_reason(): void
    {
        $this->extension($this->agent, [
            'provisioning_status' => 'failed',
            'provisioning_error' => 'agent unreachable',
        ]);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('failed', $response->json('state'));
        $this->assertSame('agent unreachable', $response->json('reason'));
        $this->assertNull($response->json('sip'));
    }

    public function test_a_disabled_asset_is_reported_as_disabled(): void
    {
        $this->extension($this->agent, ['disabled_at' => now()]);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('disabled', $response->json('state'));
        $this->assertNull($response->json('sip'));
    }

    public function test_an_inactive_employee_is_reported_as_disabled(): void
    {
        $this->extension($this->agent);
        $this->agent->forceFill(['active' => false])->save();

        $this->assertSame('disabled', $this->fetch($this->agent)->assertOk()->json('state'));
    }

    public function test_an_employee_of_an_inactive_organization_is_forbidden(): void
    {
        $this->extension($this->agent);
        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->agent->refresh())
            ->getJson(route('user.telephony.credentials'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Ready state
    |--------------------------------------------------------------------------
    */

    public function test_a_synced_asset_hands_the_owner_their_own_credentials_uncached(): void
    {
        Config::set('integrations.telephony.enabled', true);
        Config::set('integrations.telephony.sip_wss_url', 'wss://pbx.vodnox.com:8089/ws');

        $extension = $this->extension($this->agent);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('ready', $response->json('state'));
        $this->assertTrue($response->json('enabled'));
        $this->assertSame($extension->sip_username, $response->json('sip.username'));
        $this->assertSame($extension->sip_password, $response->json('sip.password'));
        $this->assertSame('pbx.vodnox.com', $response->json('sip.domain'));
        $this->assertSame('wss://pbx.vodnox.com:8089/ws', $response->json('sip.wss_url'));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function test_ice_servers_come_from_configuration_not_from_the_asset(): void
    {
        Config::set('integrations.telephony.sip_ice_url', 'stun:stun.vodnox.com:3478');
        Config::set('integrations.telephony.turn_url', 'turn:turn.vodnox.com:3478');
        Config::set('integrations.telephony.turn_username', 'turnuser');
        Config::set('integrations.telephony.turn_credential', 'turnsecret');

        $this->extension($this->agent);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertSame('stun:stun.vodnox.com:3478', $response->json('sip.ice.0.urls'));
        $this->assertSame('turn:turn.vodnox.com:3478', $response->json('sip.ice.1.urls'));
        $this->assertSame('turnuser', $response->json('sip.ice.1.username'));
    }

    /*
    |--------------------------------------------------------------------------
    | Privacy & isolation
    |--------------------------------------------------------------------------
    */

    public function test_a_colleagues_credentials_are_never_leaked(): void
    {
        $this->extension($this->agent);

        $colleague = $this->orgUser($this->organization, 'agent', ['extension' => '1002']);
        $this->extension($colleague, [
            'sip_username' => 'vdx'.$this->organization->id.'_colleague999',
            'sip_password' => 'COLLEAGUE-ONLY-SECRET',
        ]);

        $response = $this->fetch($this->agent)->assertOk();

        $this->assertStringNotContainsString('COLLEAGUE-ONLY-SECRET', $response->getContent());
        $this->assertStringNotContainsString('vdx'.$this->organization->id.'_colleague999', $response->getContent());
    }

    public function test_the_directory_lists_active_colleagues_of_the_same_organization_only(): void
    {
        $this->extension($this->agent);

        $active = $this->orgUser($this->organization, 'agent', ['extension' => '1002']);
        $this->extension($active);

        $pending = $this->orgUser($this->organization, 'agent', ['extension' => '1003']);
        $this->extension($pending, ['provisioning_status' => 'pending']);

        $foreignOrg = $this->organization();
        $foreign = $this->orgUser($foreignOrg, 'agent', ['extension' => '1004']);
        $this->extension($foreign);

        $directory = $this->fetch($this->agent)->assertOk()->json('directory');

        $this->assertCount(1, $directory);
        $this->assertSame('1002', $directory[0]['extension']);
        $this->assertSame($active->name, $directory[0]['name']);
    }

    /*
    |--------------------------------------------------------------------------
    | On-demand provisioning & registration hint
    |--------------------------------------------------------------------------
    */

    public function test_provisioning_creates_the_asset_without_any_manual_sip_setup(): void
    {
        Queue::fake();

        $this->fetch($this->agent)->assertOk();
        $this->assertSame(0, Extension::query()->count());

        $response = $this->actingAs($this->agent)
            ->postJson(route('user.telephony.provision'))
            ->assertStatus(202);

        $this->assertSame('pending', $response->json('state'));

        $extension = Extension::query()->sole();
        $this->assertSame($this->agent->id, $extension->user_id);
        $this->assertSame('1001', $extension->extension);
        $this->assertNotNull($extension->sip_username);
        $this->assertNotSame('1001', $extension->sip_username);
        $this->assertSame('pending', $extension->provisioning_status);
    }

    public function test_provisioning_allocates_a_number_when_none_is_set_yet(): void
    {
        Queue::fake();

        $fresh = $this->orgUser($this->organization, 'agent', ['extension' => null]);

        $this->actingAs($fresh)->postJson(route('user.telephony.provision'))->assertStatus(202);

        $this->assertSame('1000', Extension::query()->sole()->extension);
        $this->assertSame(
            '1001',
            app(TelephonyProvisioningService::class)->allocateExtensionNumber($this->organization),
        );
    }

    public function test_registration_records_and_clears_the_device_hint(): void
    {
        $extension = $this->extension($this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('user.telephony.registration'), ['registered' => true])
            ->assertOk();

        $this->assertNotNull($extension->fresh()->last_registered_at);

        $this->actingAs($this->agent)
            ->postJson(route('user.telephony.registration'), ['registered' => false])
            ->assertOk();

        $this->assertNull($extension->fresh()->last_registered_at);
    }

    public function test_registration_requires_a_boolean_flag(): void
    {
        $this->extension($this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('user.telephony.registration'), ['registered' => 'maybe'])
            ->assertStatus(422);
    }
}
