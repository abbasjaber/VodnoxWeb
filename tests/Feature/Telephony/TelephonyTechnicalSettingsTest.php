<?php

namespace Tests\Feature\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\AuditLog;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\TelephonyTechnicalAccount;
use App\Models\User;
use App\Services\Telephony\GatewayProvisioningService;
use App\Services\Telephony\TechnicalSessionService;
use App\Services\Telephony\TelephonyTechnicalAccountService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenant;
use Tests\Support\FakeTelephonyGateway;
use Tests\TestCase;

/**
 * The "Telephony Technical Settings" section, verified through HTTP.
 *
 * The point of the section is that the organization administrator keeps their
 * permission set — including `view_gateway_secrets` and
 * `rotate_gateway_secrets` — and STILL cannot read a SIP secret, rotate one,
 * change a device or download the guide without a live technical session. The
 * gate is authentication + authorisation on the server for every method, never
 * a hidden field or a JavaScript screen.
 *
 * Verified here:
 *   - the entry renders the sign-in only, and loads no device data;
 *   - a website account with every gateway permission is refused on every
 *     protected route (page, form, JSON, download) until the technical sign-in;
 *   - a technical account of ANOTHER organization is refused;
 *   - the correct account signs in, and the failure message never reveals
 *     whether a username exists;
 *   - the session expires on idle and on the absolute limit;
 *   - resetting the password or disabling the account invalidates open sessions;
 *   - the numbers & routing workspace keeps working without a technical session;
 *   - the technical credentials are created with the organization, are hashed,
 *     are shown once and are never rendered again;
 *   - the setup guide is gated, is no-store, and its secret inclusion needs the
 *     technical permission;
 *   - nothing secret reaches the audit log or an unauthorised response.
 */
class TelephonyTechnicalSettingsTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    private TelephonyTechnicalAccount $account;

    private string $password;

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
            'integrations.telephony.technical_session.idle_minutes' => 15,
            'integrations.telephony.technical_session.absolute_minutes' => 120,
        ]);

        Queue::fake();

        $this->organization = $this->organization();
        $this->admin = $this->orgAdmin($this->organization);

        $this->grant('organization_admin', [
            'view_gateways', 'manage_gateways', 'view_gateway_secrets', 'rotate_gateway_secrets',
        ]);
        $this->grant('supervisor', ['view_gateways']);

        $this->app->instance(TelephonyGateway::class, new FakeTelephonyGateway);
        $this->gateways = app(GatewayProvisioningService::class);

        $created = app(TelephonyTechnicalAccountService::class)
            ->createFor($this->organization, $this->admin->id);

        $this->account = $created['account'];
        $this->password = $created['password'];
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

    /**
     * Put the test client inside a live technical session, the same way the real
     * sign-in does: adopt the identity on the technical guard AND record the
     * guard's session key, so a request that re-resolves the guard (after the
     * website session is destroyed, for example) behaves like production.
     */
    private function openTechnicalSession(?User $openedBy = null, array $payloadOverrides = []): void
    {
        $openedBy ??= $this->admin;

        $this->actingAs($openedBy);

        $guard = $this->app['auth']->guard('technical');
        $guard->setUser($this->account);

        $now = now()->timestamp;

        $this->withSession([
            TechnicalSessionService::SESSION_KEY => array_merge([
                'account_id' => $this->account->getKey(),
                'organization_id' => $this->account->organization_id,
                'version' => $this->account->session_version,
                'started_at' => $now,
                'last_activity_at' => $now,
                'opened_by_user_id' => $openedBy->getKey(),
            ], $payloadOverrides),
        ]);

        $this->app['session']->put($guard->getName(), $this->account->getAuthIdentifier());
    }

    private function device(?Organization $organization = null, string $model = 'goip-1'): GatewayDevice
    {
        $organization ??= $this->organization;

        return $this->gateways->createDevice(
            $organization,
            $this->admin->id,
            ['label' => 'GoIP '.$organization->id, 'model' => $model],
            [['label' => 'SIM 1', 'sim_number' => '0770', 'enabled' => true]],
        );
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

    /*
    |--------------------------------------------------------------------------
    | The entry: sign-in only, and never device data
    |--------------------------------------------------------------------------
    */

    public function test_the_entry_renders_the_sign_in_and_loads_no_device_data(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $response = $this->actingAs($this->admin)->get(route('company.gateways.index'));

        $response->assertOk();
        $response->assertSee(__('telephony_technical.gate_heading'));
        $response->assertDontSee($device->label);
        $response->assertDontSee($line->sip_username);
        $response->assertDontSee($line->sip_password);
        $response->assertDontSee($line->inbound_number);
    }

    public function test_a_plain_employee_has_no_entry_to_the_section(): void
    {
        // `agent` is not part of the company management roles at all.
        $this->actingAs($this->orgUser($this->organization, 'agent'))
            ->get(route('company.gateways.index'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The gate: a website account alone is never enough
    |--------------------------------------------------------------------------
    */

    public function test_an_organization_admin_with_every_permission_is_refused_without_a_technical_session(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();
        $secret = $line->sip_password;

        // GET (page / download) → redirected to the gate, never the secret.
        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'))
            ->assertSessionHas('error', __('telephony_technical.session_required'));

        $this->actingAs($this->admin)
            ->get(route('company.gateways.setup-guide', $device))
            ->assertRedirect(route('company.gateways.index'));

        $this->actingAs($this->admin)
            ->get(route('company.gateways.settings', $device))
            ->assertRedirect(route('company.gateways.index'));

        // AJAX → a no-store 401 with no payload.
        $json = $this->actingAs($this->admin)
            ->getJson(route('company.gateways.lines.secret', [$device, $line]));

        $json->assertStatus(401);
        $json->assertJsonPath('success', false);
        $this->assertStringNotContainsString($secret, $json->getContent());
        $this->assertStringContainsString('no-store', (string) $json->headers->get('Cache-Control'));

        // Writes → refused outright.
        $this->actingAs($this->admin)
            ->post(route('company.gateways.store'), [
                'label' => 'GoIP', 'model' => 'goip-1', 'lines' => [['label' => 'SIM 1']],
            ])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('company.gateways.update', $device), ['label' => 'Hacked', 'model' => 'goip-1'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('company.gateways.toggle', $device))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('company.gateways.lines.rotate', [$device, $line]))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('company.gateways.lines.update', [$device, $line]), ['label' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('company.gateways.destroy', $device))
            ->assertForbidden();

        // Nothing changed, and no reveal was audited.
        $device->refresh();
        $this->assertNotSame('Hacked', $device->label);
        $this->assertSame($secret, $line->fresh()->sip_password);
        $this->assertSame(0, AuditLog::query()->where('action', 'gateway.secret_viewed')->count());
    }

    public function test_a_technical_account_of_another_organization_is_refused(): void
    {
        $other = $this->organization();
        $foreign = app(TelephonyTechnicalAccountService::class)->createFor($other, null);

        $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.login'), [
                'username' => $foreign['account']->username,
                'password' => $foreign['password'],
            ])
            ->assertSessionHasErrors('username');

        $this->assertGuest('technical');

        $failure = AuditLog::query()->where('action', 'telephony_technical.login_failed')->firstOrFail();

        $this->assertSame($this->organization->id, $failure->organization_id);
        $this->assertStringNotContainsString($foreign['password'], json_encode($failure->new_values));
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    */

    public function test_the_correct_technical_account_signs_in_and_opens_the_workspace(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.login'), [
                'username' => $this->account->username,
                'password' => $this->password,
            ]);

        $response->assertRedirect(route('company.gateways.index'));
        $response->assertSessionHas('status', __('telephony_technical.session_started'));

        $this->assertAuthenticated('technical');
        $this->assertNotNull($this->account->fresh()->last_login_at);

        // The workspace now renders, and the session is audited.
        $this->actingAs($this->admin)
            ->get(route('company.gateways.index'))
            ->assertOk()
            ->assertSee($device->label)
            ->assertSee(__('telephony_technical.session_badge'));

        $login = AuditLog::query()->where('action', 'telephony_technical.login')->firstOrFail();

        $this->assertSame($this->account->id, $login->technical_account_id);
        $this->assertSame($this->admin->id, $login->user_id);
        $this->assertStringNotContainsString($this->password, json_encode($login->toArray()));
    }

    public function test_the_failure_message_never_reveals_whether_a_username_exists(): void
    {
        $unknown = $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.login'), [
                'username' => 'vdt_does_not_exist', 'password' => 'whatever-value',
            ]);

        $wrongPassword = $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.login'), [
                'username' => $this->account->username, 'password' => 'wrong-password-value',
            ]);

        $unknown->assertSessionHasErrors('username');
        $wrongPassword->assertSessionHasErrors('username');

        $this->assertSame(
            $unknown->getSession()->get('errors')->get('username'),
            $wrongPassword->getSession()->get('errors')->get('username'),
        );

        $this->assertSame(
            [__('telephony_technical.invalid_credentials')],
            $wrongPassword->getSession()->get('errors')->get('username'),
        );

        // Neither the submitted password nor the real one is stored.
        foreach (AuditLog::query()->where('action', 'telephony_technical.login_failed')->get() as $row) {
            $this->assertStringNotContainsString('wrong-password-value', json_encode($row->new_values));
            $this->assertStringNotContainsString($this->password, json_encode($row->new_values));
        }
    }

    public function test_the_sign_in_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($this->admin)
                ->post(route('company.telephony-technical.login'), [
                    'username' => 'vdt_guessing', 'password' => 'nope',
                ]);
        }

        $blocked = $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.login'), [
                'username' => 'vdt_guessing', 'password' => 'nope',
            ]);

        $blocked->assertRedirect(route('company.gateways.index'));
        $blocked->assertSessionHas('error', __('telephony_technical.too_many_attempts'));
    }

    /*
    |--------------------------------------------------------------------------
    | Lifetime and invalidation
    |--------------------------------------------------------------------------
    */

    public function test_the_session_expires_after_the_idle_window(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $this->openTechnicalSession(payloadOverrides: [
            'started_at' => now()->subMinutes(16)->timestamp,
            'last_activity_at' => now()->subMinutes(16)->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'));
    }

    public function test_the_session_expires_after_the_absolute_limit_even_while_active(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $this->openTechnicalSession(payloadOverrides: [
            'started_at' => now()->subMinutes(121)->timestamp,
            'last_activity_at' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'));
    }

    public function test_the_technical_session_ends_on_demand_without_touching_the_company_sign_in(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $this->openTechnicalSession();

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('company.telephony-technical.logout'))
            ->assertRedirect(route('company.gateways.index'));

        $this->assertNull($this->app['session']->get(TechnicalSessionService::SESSION_KEY));

        // The company sign-in is untouched, but the device routes are closed again.
        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telephony_technical.logout',
            'technical_account_id' => $this->account->id,
        ]);
    }

    public function test_logging_out_of_the_company_account_also_ends_the_technical_session(): void
    {
        $this->openTechnicalSession();

        $this->assertNotNull($this->app['session']->get(TechnicalSessionService::SESSION_KEY));

        $this->actingAs($this->admin)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        // The website logout destroys the whole session, so the technical
        // session payload is gone with it.
        $this->assertNull($this->app['session']->get(TechnicalSessionService::SESSION_KEY));
    }

    public function test_resetting_the_password_invalidates_open_sessions(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $this->openTechnicalSession();

        app(TelephonyTechnicalAccountService::class)->resetPassword($this->account);

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'));
    }

    public function test_disabling_the_account_invalidates_open_sessions(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();

        $this->openTechnicalSession();

        app(TelephonyTechnicalAccountService::class)->setActive($this->account, false);

        $this->actingAs($this->admin)
            ->get(route('company.gateways.lines.secret', [$device, $line]))
            ->assertRedirect(route('company.gateways.index'));
    }

    /*
    |--------------------------------------------------------------------------
    | Numbers & routing keep working without a technical session
    |--------------------------------------------------------------------------
    */

    public function test_the_numbers_and_routing_workspace_is_reachable_without_a_technical_session(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();
        $destination = $this->extension($this->organization);

        app(\App\Services\Telephony\GatewayLineRoutingService::class)->routeFor($line)
            ->forceFill(['default_destination_extension_id' => $destination->id])
            ->save();

        // No technical session at all: the administrator still owns routing,
        // and the page must never leak the SIP password.
        $this->actingAs($this->admin)
            ->get(route('company.numbers.routing.edit', ['gateway' => $device, 'line' => $line]))
            ->assertOk()
            ->assertDontSee($line->sip_password);
    }

    /*
    |--------------------------------------------------------------------------
    | The independent technical account (super admin)
    |--------------------------------------------------------------------------
    */

    public function test_creating_an_organization_creates_a_hashed_technical_account_shown_once(): void
    {
        $superAdmin = $this->superAdmin();

        // The organization administrator role must exist for the created admin.
        $this->role('organization_admin');

        $response = $this->actingAs($superAdmin)
            ->post(route('admin.organizations.store'), [
                'code' => 'ORG-NEW-1',
                'name_ar' => 'مؤسسة جديدة',
                'name_en' => 'New Org',
                'status' => 'active',
                'language' => 'ar',
                'admin_name' => 'Admin',
                'admin_username' => 'new_org_admin',
                'admin_password' => 'password123',
            ]);

        $response->assertOk();
        // Symfony normalises and re-orders the Cache-Control directives, so the
        // meaningful guarantee is that the response is not storable at all.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        $organization = Organization::query()->where('code', 'ORG-NEW-1')->firstOrFail();
        $account = $organization->technicalAccount()->firstOrFail();

        $plain = $this->extractCopyValues($response->getContent())[1] ?? null;

        $this->assertNotNull($plain);
        $this->assertSame($account->username, $this->extractCopyValues($response->getContent())[0]);
        $this->assertNotSame($plain, $account->password);
        $this->assertTrue(Hash::check($plain, $account->password));

        // Re-reading the organization page never shows the password again.
        $this->actingAs($superAdmin)
            ->get(route('admin.organizations.show', ['organization' => $organization, 'tab' => 'technical']))
            ->assertOk()
            ->assertSee($account->username)
            ->assertDontSee($plain);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telephony_technical.account_created',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_only_the_super_admin_may_create_reset_or_disable_the_technical_account(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.organizations.technical.store', $this->organization))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.organizations.technical.reset', $this->organization))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->patch(route('admin.organizations.technical.status', $this->organization), ['active' => 0])
            ->assertForbidden();

        $this->assertTrue($this->account->fresh()->active);
    }

    public function test_the_super_admin_can_reset_and_disable_without_ever_recovering_the_password(): void
    {
        $superAdmin = $this->superAdmin();

        $reset = $this->actingAs($superAdmin)
            ->post(route('admin.organizations.technical.reset', $this->organization));

        $reset->assertOk();
        $newPassword = $this->extractCopyValues($reset->getContent())[1] ?? null;

        $this->assertNotNull($newPassword);
        $this->assertNotSame($this->password, $newPassword);
        $this->assertTrue(Hash::check($newPassword, $this->account->fresh()->password));

        $this->actingAs($superAdmin)
            ->patch(route('admin.organizations.technical.status', $this->organization), ['active' => 0])
            ->assertRedirect(route('admin.organizations.show', ['organization' => $this->organization, 'tab' => 'technical']));

        $this->account->refresh();
        $this->assertFalse($this->account->active);
        $this->assertNotNull($this->account->disabled_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telephony_technical.account_reset',
            'organization_id' => $this->organization->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telephony_technical.account_disabled',
            'organization_id' => $this->organization->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The setup guide
    |--------------------------------------------------------------------------
    */

    public function test_the_setup_guide_downloads_as_a_private_pdf(): void
    {
        $device = $this->device();
        $this->openTechnicalSession();

        $response = $this->actingAs($this->admin)
            ->get(route('company.gateways.setup-guide', $device));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $audit = AuditLog::query()->where('action', 'gateway.setup_guide_downloaded')->firstOrFail();

        $this->assertFalse($audit->new_values['include_passwords']);
        $this->assertSame($this->account->id, $audit->technical_account_id);
        $this->assertSame($this->organization->id, $audit->organization_id);

        // The filename carries the device id and the date only.
        $this->assertMatchesRegularExpression(
            '/vodnox-setup-guide-'.$device->getKey().'-\d{8}\.pdf/',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_including_sip_passwords_needs_the_secret_permission_on_top_of_the_technical_session(): void
    {
        $device = $this->device();
        $supervisor = $this->orgUser($this->organization, 'supervisor');

        // A supervisor holds `view_gateways` and (here) a live technical session,
        // but not the permission to reveal secrets.
        $this->openTechnicalSession($supervisor);

        $this->actingAs($supervisor)
            ->get(route('company.gateways.setup-guide', $device))
            ->assertOk();

        $this->actingAs($supervisor)
            ->get(route('company.gateways.setup-guide', $device).'?include_passwords=1')
            ->assertForbidden();

        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'gateway.setup_guide_downloaded')
                ->where('new_values->include_passwords', true)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Secrets never leak
    |--------------------------------------------------------------------------
    */

    public function test_the_secret_is_revealed_only_inside_a_live_technical_session_and_never_logged(): void
    {
        $device = $this->device();
        $line = $device->lines()->first();
        $secret = $line->sip_password;

        $this->openTechnicalSession();

        $response = $this->actingAs($this->admin)
            ->getJson(route('company.gateways.lines.secret', [$device, $line]));

        $response->assertOk();
        $response->assertJsonPath('sip_password', $secret);

        // The reveal is audited by identity, never by value.
        foreach (AuditLog::query()->get() as $row) {
            $encoded = json_encode([$row->old_values, $row->new_values, $row->action]);
            $this->assertStringNotContainsString($secret, (string) $encoded);
        }
    }

    /**
     * The credentials page renders each value in a `<span data-copy-value>`; this
     * returns them in the order they appear (username, password, sign-in URL).
     *
     * @return array<int, string>
     */
    private function extractCopyValues(string $html): array
    {
        preg_match_all('/<span data-copy-value>(.*?)<\/span>/s', $html, $matches);

        return array_map('trim', $matches[1] ?? []);
    }
}
