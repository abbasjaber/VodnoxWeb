<?php

namespace Tests\Feature\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\AuditLog;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\GatewayLineRoute;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\TelephonyTechnicalAccount;
use App\Models\User;
use App\Services\Telephony\GatewayProvisioningService;
use App\Services\Telephony\TechnicalSessionService;
use App\Services\Telephony\TelephonyTechnicalAccountService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
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

        $ports = (int) (config("integrations.telephony.gateway_profiles.{$model}.lines") ?: 1);

        $lines = [];

        for ($index = 1; $index <= $ports; $index++) {
            $lines[] = [
                'label' => 'SIM '.$index,
                'sim_number' => '0770'.($ports > 1 ? $index : ''),
                'enabled' => true,
            ];
        }

        return $this->gateways->createDevice(
            $organization,
            $this->admin->id,
            ['label' => 'GoIP '.$organization->id, 'model' => $model],
            $lines,
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

    /**
     * Render the guide's HTML the same way the controller does. mPDF compresses
     * and subsets what it converts, so the exact text handed to it is the layer
     * the English-only rule and the printed values can be asserted on.
     */
    private function renderGuide(GatewayDevice $device, bool $includePasswords = false): string
    {
        $device->load('lines.route.defaultDestination');

        return view('company.gateway-setup-guide', [
            'org' => $device->organization,
            'gateway' => $device,
            'serverSettings' => $this->gateways->serverSettings(),
            'profile' => $this->gateways->profiles()[$device->model] ?? null,
            'includePasswords' => $includePasswords,
            'exportedAt' => now(),
        ])->render();
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

    /**
     * A live technical session, and the permission to reveal secrets, still
     * never reach across organizations: the guide of a device that belongs to
     * another organization is not found.
     */
    public function test_the_setup_guide_never_crosses_the_organization_boundary(): void
    {
        $other = $this->organization();
        $foreignDevice = $this->device($other, 'goip-1');

        $this->openTechnicalSession();

        $this->actingAs($this->admin)
            ->get(route('company.gateways.setup-guide', $foreignDevice))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('company.gateways.setup-guide', $foreignDevice).'?include_passwords=1')
            ->assertNotFound();

        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'gateway.setup_guide_downloaded')->count(),
        );
    }

    /**
     * The download must be a real, complete PDF file — not an HTML error page
     * carrying a PDF content type — for a single-port and a multi-port device,
     * in Arabic and English, with and without the passwords.
     */
    public function test_the_download_is_a_real_pdf_for_every_device_and_language(): void
    {
        $single = $this->device(model: 'goip-1');
        $multi = $this->device(model: 'goip-4');

        $this->openTechnicalSession();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$single, $multi] as $device) {
                foreach ([false, true] as $withPasswords) {
                    $url = route('company.gateways.setup-guide', $device)
                        .($withPasswords ? '?include_passwords=1' : '');

                    $response = $this->actingAs($this->admin)->get($url);

                    $case = sprintf('%s/%s/%s', $locale, $device->model, $withPasswords ? 'secrets' : 'safe');

                    $response->assertOk();
                    $response->assertHeader('Content-Type', 'application/pdf');
                    $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
                    // Symfony re-orders and normalises the directives, so the
                    // meaningful guarantee is that the response is not storable.
                    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

                    $pdf = $response->streamedContent();

                    // A real PDF file always starts with its magic signature and
                    // always ends with the trailer keyword.
                    $this->assertStringStartsWith('%PDF-', $pdf, "Not a PDF for {$case}");
                    $this->assertStringContainsString('%%EOF', $pdf, "Truncated PDF for {$case}");

                    // The exported file never carries the technical sign-in
                    // password or any organization sign-in secret.
                    $this->assertStringNotContainsString($this->password, $pdf, "Leaked a sign-in password for {$case}");
                }
            }
        }
    }

    /**
     * Which secrets the guide carries is decided by the template that mPDF
     * converts. mPDF compresses its content streams and subsets its fonts, so
     * a password can no longer be grepped out of the finished file; the party
     * that must be verified is therefore the exact HTML handed to mPDF (which
     * is what test_the_download_is_a_real_pdf_* proves was converted).
     */
    public function test_the_guide_carries_sip_passwords_only_when_explicitly_requested(): void
    {
        $device = $this->device(model: 'goip-4');

        $secrets = $device->lines->map(fn ($line) => (string) $line->sip_password)->all();

        $this->assertCount(4, $secrets);

        $safe = $this->renderGuide($device, false);
        $withSecrets = $this->renderGuide($device, true);

        // The default copy lists every port but no SIP password at all.
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $safe);
            $this->assertStringContainsString($secret, $withSecrets);
        }

        // Each copy carries its own, unmistakable banner. The secrets banner has
        // a class of its own, distinct from the "profile not verified" warning
        // this model legitimately carries.
        $this->assertStringContainsString('<div class="warn secrets">', $withSecrets);
        $this->assertStringNotContainsString('class="warn secrets"', $safe);
        $this->assertStringContainsString('This copy contains the current SIP passwords.', $withSecrets);
        $this->assertStringNotContainsString('This copy contains the current SIP passwords.', $safe);

        // The copy without secrets says so, and tells the engineer where the
        // password comes from instead of printing a placeholder.
        $this->assertStringContainsString('This copy does not contain any SIP password.', $safe);

        // Whatever the caller asks for, the technical sign-in password never
        // enters the guide.
        $this->assertStringNotContainsString($this->password, $safe);
        $this->assertStringNotContainsString($this->password, $withSecrets);
    }

    /**
     * The guide is English and left to right on its own terms, whatever the
     * dashboard language was when the export was started. A name stored in
     * another script is replaced by a neutral English identifier — never
     * transliterated, never translated, never printed as-is.
     */
    public function test_the_guide_is_english_only_and_left_to_right_in_either_dashboard_language(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $organization = $this->organization(['name_en' => 'مؤسسة اختبار', 'language' => 'ar']);
            $device = $this->device($organization, 'goip-1');
            $device->forceFill(['label' => 'بوابة المكتب'])->save();
            $device = $device->refresh();

            $html = $this->renderGuide($device, true);

            $this->assertStringContainsString('<html lang="en" dir="ltr">', $html, "Not LTR for locale {$locale}");
            $this->assertStringContainsString('VODNOX — GSM Gateway Setup Guide', $html);

            // Not one Arabic character is rendered, in any element.
            $this->assertSame(
                0,
                preg_match('/[\x{0600}-\x{06FF}]/u', $html),
                "Arabic text leaked into the guide for locale {$locale}",
            );

            // The neutral identifiers replace the non-English names.
            $this->assertStringContainsString('Organization '.$organization->code, $html);
            $this->assertStringContainsString('Gateway #'.$device->getKey(), $html);
            $this->assertStringNotContainsString('مؤسسة اختبار', $html);
            $this->assertStringNotContainsString('بوابة المكتب', $html);
        }
    }

    /**
     * The step paths and the field labels must match the English interface of
     * the exercised device literally, and the inbound CID mode must be the one
     * that was confirmed on real hardware.
     */
    public function test_the_guide_names_the_real_device_pages_and_the_verified_cid_mode(): void
    {
        $device = $this->device(model: 'goip-1');
        $html = $this->renderGuide($device, true);

        // The three real pages of the exercised GoIP interface.
        $this->assertStringContainsString('Configurations &gt; Basic VoIP', $html);
        $this->assertStringContainsString('Configurations &gt; Call In', $html);
        $this->assertStringContainsString('Configurations &gt; Media', $html);
        $this->assertStringContainsString('Path on the device:', $html);

        // The value exercised on hardware — and never the wrong one the earlier
        // guide printed.
        $this->assertStringContainsString('Use RemotePartyID', $html);
        $this->assertStringNotContainsString('P-Asserted', $html);
        $this->assertStringNotContainsString('PAsserted', $html);

        // Every field the engineer types into, named as the device names it.
        foreach ([
            'Endpoint Type', 'Config Mode', 'Authentication ID', 'Password',
            'SIP Proxy', 'SIP Registrar', 'Re-register Period', 'Phone Number',
            'Home Domain', 'Outbound Proxy',
            'Call IN via GSM', 'CID Forward Mode', 'Forwarding to VoIP Number',
            'Media Encryption', 'Media NAT Traversal', 'Signaling NAT Traversal',
            'Audio Codec Preference', 'RTP Port Range',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "Missing field name: {$field}");
        }

        // The practical wording the engineer follows.
        $this->assertStringContainsString('Click Save Changes.', $html);
        $this->assertStringContainsString('Step 1 — Connect the gateway', $html);
        $this->assertStringContainsString('Step 2 — Configure the SIP account', $html);
        $this->assertStringContainsString('Step 3 — Configure incoming GSM calls', $html);
        $this->assertStringContainsString('Step 4 — Configure media', $html);
        $this->assertStringContainsString('Step 5 — Verify registration and test a call', $html);
    }

    /**
     * Every printed value must come from the device rows themselves: the SIP
     * identity, the inbound path of the port and the server settings. The port's
     * inbound path is its own number, never the employee extension.
     */
    public function test_the_guide_prints_the_port_values_from_the_database(): void
    {
        $device = $this->device(model: 'goip-1');
        $line = $device->lines()->firstOrFail();
        $extension = $this->extension($this->organization, '1001');

        GatewayLineRoute::firstOrCreate(
            ['gateway_line_id' => $line->getKey()],
            ['organization_id' => $this->organization->getKey()],
        )->forceFill([
            'default_destination_extension_id' => $extension->getKey(),
        ])->save();

        $device = $device->refresh()->load('lines.route.defaultDestination');

        $safe = $this->renderGuide($device, false);
        $withSecrets = $this->renderGuide($device, true);

        // The identity and the server settings are the stored ones.
        $this->assertStringContainsString($line->sip_username, $withSecrets);
        $this->assertStringContainsString($line->sip_password, $withSecrets);
        $this->assertStringContainsString('sip.gw.test:5060', $withSecrets);
        $this->assertStringContainsString('gw.test', $withSecrets);
        $this->assertStringContainsString((string) config('integrations.telephony.gateway_registration_interval'), $withSecrets);

        // The port's own inbound number, and it is not the employee extension.
        $this->assertSame('5000', (string) $line->inbound_number);
        $this->assertStringContainsString($line->inbound_number, $withSecrets);
        $this->assertNotSame($extension->extension, (string) $line->inbound_number);
        $this->assertStringContainsString($extension->extension, $withSecrets);

        // Without the explicit request, the secret is absent from the file.
        $this->assertStringNotContainsString($line->sip_password, $safe);
    }

    /**
     * A multi-port device gets one clearly separated section per port — never a
     * crowded horizontal table that mixes several accounts together.
     */
    public function test_a_multi_port_guide_gives_every_port_its_own_section(): void
    {
        $single = $this->device(model: 'goip-1');
        $multi = $this->device(model: 'goip-4');

        $singleHtml = $this->renderGuide($single, true);
        $multiHtml = $this->renderGuide($multi, true);

        $this->assertSame(1, substr_count($singleHtml, '<div class="port">'));
        $this->assertSame(4, substr_count($multiHtml, '<div class="port">'));

        foreach ($multi->lines as $line) {
            $this->assertStringContainsString('Port #'.$line->line_index, $multiHtml);
            $this->assertStringContainsString($line->sip_username, $multiHtml);
            $this->assertStringContainsString($line->inbound_number, $multiHtml);
        }

        // Each port has its own identity, so copying one account onto another
        // port is detectable.
        $usernames = $multi->lines->pluck('sip_username')->all();
        $this->assertSame(count($usernames), count(array_unique($usernames)));

        // A model that was exercised is stated as verified; a model that was not
        // is stated as not verified instead of pretending.
        $this->assertStringContainsString('verified on real hardware', $singleHtml);
        $this->assertStringContainsString('not verified on real hardware', $multiHtml);
        $this->assertStringNotContainsString('not verified on real hardware', $singleHtml);
    }

    /**
     * Ports that carry no SIM are named in one short line and never print a
     * credential, even in a copy that carries the passwords.
     */
    public function test_ports_that_are_not_in_use_are_listed_without_any_credential(): void
    {
        $device = $this->gateways->createDevice(
            $this->organization,
            $this->admin->id,
            ['label' => 'GoIP partial', 'model' => 'goip-4'],
            [
                ['label' => 'SIM 1', 'sim_number' => '07700001', 'enabled' => true],
                ['label' => 'SIM 2', 'sim_number' => '07700002', 'enabled' => false],
                ['label' => 'SIM 3', 'sim_number' => '07700003', 'enabled' => false],
                ['label' => 'SIM 4', 'sim_number' => '07700004', 'enabled' => false],
            ],
        );

        $used = $device->lines->firstWhere('enabled', true);
        $unused = $device->lines->where('enabled', false);

        $html = $this->renderGuide($device, true);

        $this->assertSame(1, substr_count($html, '<div class="port">'));
        $this->assertStringContainsString('Ports not in use', $html);

        $this->assertStringContainsString($used->sip_username, $html);
        $this->assertStringContainsString($used->sip_password, $html);

        foreach ($unused as $line) {
            $this->assertStringContainsString('#'.$line->line_index.' ('.$line->label.')', $html);
            $this->assertStringNotContainsString($line->sip_username, $html);
            $this->assertStringNotContainsString($line->sip_password, $html);
        }
    }

    /**
     * The guide has to say where registration is actually read and how the call
     * is proven end to end, because a green registration alone is not audio.
     */
    public function test_the_guide_explains_how_to_verify_registration_and_test_the_call(): void
    {
        $device = $this->device(model: 'goip-1');
        $html = $this->renderGuide($device, true);

        // Where the engineer checks, on the device and in VODNOX.
        $this->assertStringContainsString('Status &gt; VoIP Status', $html);
        $this->assertStringContainsString('Refresh status', $html);
        $this->assertStringContainsString('Registered on device', $html);
        $this->assertStringContainsString('dial the mobile number of the SIM', $html);

        // Registration is not audio, and the troubleshooting table says what to
        // check next for the three failures that matter.
        $this->assertStringContainsString('does not prove that the', $html);
        $this->assertStringContainsString('Troubleshooting', $html);
        $this->assertStringContainsString('The port never registers', $html);
        $this->assertStringContainsString('does not ring', $html);
        $this->assertStringContainsString('nobody hears the other side', $html);
    }

    /**
     * mPDF only looks a couple of lines ahead for `page-break-after: avoid`, so a
     * heading followed by a table or a box was left alone at the foot of a page
     * (found by reading the exported PDF, not by a unit test). Every heading of
     * the guide is therefore grouped with the block that belongs to it, and this
     * guards that grouping against a later edit.
     */
    public function test_every_heading_is_grouped_with_the_block_that_follows_it(): void
    {
        $device = $this->device(model: 'goip-4');
        $html = $this->renderGuide($device, true);

        $this->assertStringContainsString('.keep { page-break-inside: avoid; }', $html);

        // Only the body is inspected; the <style> block also carries selectors.
        $body = substr($html, (int) strpos($html, '<body>'));

        preg_match_all('/<h([23])[ >]/', $body, $matches, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as [$heading, $offset]) {
            $before = rtrim(substr($body, 0, $offset));

            // A heading is safe when it opens together with the block it belongs
            // to: a grouped section, or the port card that holds its own fields.
            $grouped = str_ends_with($before, '<div class="keep">')
                || str_ends_with($before, '<div class="port">');

            $this->assertTrue(
                $grouped,
                'This heading is not grouped with its content: '.$heading
            );
        }
    }

    public function test_the_setup_guide_exports_a_real_pdf_when_a_port_has_a_default_destination(): void
    {
        $device = $this->device(model: 'goip-1');
        $line = $device->lines()->firstOrFail();
        $destination = $this->extension($this->organization, '1450');

        GatewayLineRoute::firstOrCreate(
            ['gateway_line_id' => $line->getKey()],
            ['organization_id' => $this->organization->getKey()],
        )->forceFill([
            'default_destination_extension_id' => $destination->getKey(),
        ])->save();

        $this->openTechnicalSession();

        foreach ([false, true] as $withPasswords) {
            $response = $this->actingAs($this->admin)->get(
                route('company.gateways.setup-guide', $device).($withPasswords ? '?include_passwords=1' : '')
            );

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');

            $pdf = $response->streamedContent();

            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringContainsString('%%EOF', $pdf);
        }
    }

    /**
     * The export must never answer a failure with a false success. When the PDF
     * cannot be built, the caller is redirected back with an error that carries
     * a reference, and the real exception is logged (with the diagnostic
     * facts), never swallowed.
     */
    public function test_a_failed_export_reports_a_reference_instead_of_a_false_success(): void
    {
        $device = $this->device();
        $this->openTechnicalSession();

        Log::spy();

        // `serverSettings()` is called inside the export's try block; making it
        // throw stands in for any runtime failure (a missing mPDF package, an
        // unreadable font, an unwritable temp directory).
        $this->partialMock(GatewayProvisioningService::class, function ($mock): void {
            $mock->shouldReceive('serverSettings')->andThrow(new RuntimeException('simulated export failure'));
        });

        $response = $this->actingAs($this->admin)
            ->get(route('company.gateways.setup-guide', $device));

        $response->assertRedirect(route('company.gateways.index'));

        $error = (string) $response->getSession()->get('error');

        $this->assertMatchesRegularExpression('/SG-[A-Z0-9]{8}/', $error);
        // The message the user sees is the translated one, never the raw exception.
        $this->assertStringNotContainsString('simulated export failure', $error);
        $this->assertStringNotContainsString('vendor/mpdf', $error);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'gateway.setup_guide_failed')
                    && ($context['exception'] ?? null) === RuntimeException::class
                    && ($context['message'] ?? null) === 'simulated export failure'
                    && array_key_exists('file', $context)
                    && array_key_exists('line', $context)
                    && array_key_exists('mpdf_available', $context)
                    && array_key_exists('temp_dir_writable', $context);
            });

        // No "downloaded" audit row and no PDF were produced.
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'gateway.setup_guide_downloaded')->count(),
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
