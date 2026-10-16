<?php

namespace Tests\Feature\Telephony;

use App\Jobs\ProvisionTelephonyJob;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telephony\SipCredentialService;
use App\Services\Telephony\TelephonyProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Server-side provisioning: the automatic creation of one independent SIP
 * account per employee.
 *
 * What is verified here:
 *   - internal numbers are unique *per organization* (two organizations may
 *     both use "1001");
 *   - the SIP identity is globally unique and never the short number;
 *   - the SIP password is a separate secret, encrypted at rest and never
 *     written into the provisioning job payload;
 *   - syncing/disabling is idempotent (no duplicate endpoint, no duplicate
 *     extension row) and always queued, never inline.
 */
class TelephonyProvisioningTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private TelephonyProvisioningService $provisioning;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->organization = $this->organization();
        $this->provisioning = app(TelephonyProvisioningService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Number allocation
    |--------------------------------------------------------------------------
    */

    public function test_numbers_start_at_1000_and_skip_taken_ones(): void
    {
        $this->assertSame('1000', $this->provisioning->allocateExtensionNumber($this->organization));

        Extension::create([
            'organization_id' => $this->organization->id,
            'extension' => '1000',
            'status' => 'active',
        ]);

        $this->assertSame('1001', $this->provisioning->allocateExtensionNumber($this->organization));

        Extension::create([
            'organization_id' => $this->organization->id,
            'extension' => '1001',
            'status' => 'active',
        ]);
        Extension::create([
            'organization_id' => $this->organization->id,
            'extension' => '1003',
            'status' => 'active',
        ]);

        $this->assertSame('1002', $this->provisioning->allocateExtensionNumber($this->organization));
    }

    public function test_two_organizations_may_use_the_same_internal_number(): void
    {
        $other = $this->organization();

        foreach ([$this->organization, $other] as $organization) {
            $this->provisioning->ensureOrganizationContext($organization);

            Extension::create([
                'organization_id' => $organization->id,
                'extension' => $this->provisioning->allocateExtensionNumber($organization),
                'status' => 'active',
            ]);
        }

        $this->assertSame(2, Extension::query()->where('extension', '1000')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    public function test_the_sip_identity_is_globally_unique_and_not_the_short_number(): void
    {
        $credentials = app(SipCredentialService::class);
        $other = $this->organization();

        $first = Extension::create([
            'organization_id' => $this->organization->id,
            'extension' => '1001',
            'status' => 'active',
        ]);
        $second = Extension::create([
            'organization_id' => $other->id,
            'extension' => '1001',
            'status' => 'active',
        ]);

        $credentials->ensure($first);
        $credentials->ensure($second);

        $this->assertNotSame($first->sip_username, $second->sip_username);
        $this->assertNotSame('1001', $first->sip_username);
        $this->assertNotSame($first->sip_password, $second->sip_password);
        $this->assertStringStartsWith('vdx'.$this->organization->id.'_', $first->sip_username);
        $this->assertSame('pbx.vodnox.com', $first->sip_domain);
    }

    public function test_ensuring_a_user_builds_the_asset_and_mirrors_the_number(): void
    {
        $agent = $this->orgUser($this->organization, 'agent', ['extension' => null]);

        $extension = $this->provisioning->ensureExtensionForUser($agent);

        $this->assertNotNull($extension);
        $this->assertSame('1000', $extension->extension);
        $this->assertSame('pending', $extension->provisioning_status);
        $this->assertSame('active', $extension->status);
        $this->assertNotNull($extension->sip_username);
        $this->assertNotNull($extension->sip_password);

        $this->assertSame('1000', $agent->fresh()->extension);
        $this->assertSame(
            'vodnox_org_'.$this->organization->id,
            $this->organization->fresh()->telephony_context,
        );
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $extension = $this->provisioning->ensureExtensionForUser(
            $this->orgUser($this->organization, 'agent'),
        );

        $plain = $extension->sip_password;
        $raw = DB::table('extensions')->where('id', $extension->id)->value('sip_password');

        $this->assertNotSame($plain, $raw);
        $this->assertSame($plain, $extension->fresh()->sip_password);
    }

    /*
    |--------------------------------------------------------------------------
    | Queueing
    |--------------------------------------------------------------------------
    */

    public function test_syncing_marks_the_asset_pending_and_queues_one_job_without_secrets(): void
    {
        $extension = $this->provisioning->ensureExtensionForUser(
            $this->orgUser($this->organization, 'agent'),
        );
        $extension->forceFill(['provisioning_status' => 'failed', 'provisioning_error' => 'boom'])->save();

        $this->provisioning->syncExtension($extension);

        $this->assertSame('pending', $extension->fresh()->provisioning_status);
        $this->assertNull($extension->fresh()->provisioning_error);

        Queue::assertPushed(ProvisionTelephonyJob::class, function (ProvisionTelephonyJob $job) use ($extension) {
            return $job->type === 'provision_extension'
                && $job->extensionId === $extension->id
                && $job->payload['sip_username'] === $extension->sip_username
                && $job->payload['context'] === 'vodnox_org_'.$this->organization->id
                && ! array_key_exists('sip_password', $job->payload);
        });
    }

    public function test_disabling_an_extension_marks_it_and_never_creates_a_duplicate(): void
    {
        $agent = $this->orgUser($this->organization, 'agent');
        $extension = $this->provisioning->ensureExtensionForUser($agent);

        $this->provisioning->disableExtension($extension);

        $this->assertNotNull($extension->fresh()->disabled_at);
        $this->assertSame('pending', $extension->fresh()->provisioning_status);
        $this->assertSame(1, Extension::query()->where('user_id', $agent->id)->count());

        Queue::assertPushed(ProvisionTelephonyJob::class, function (ProvisionTelephonyJob $job) use ($extension) {
            return $job->type === 'provision_extension'
                && $job->extensionId === $extension->id
                && $job->payload['enabled'] === false;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Organization-wide reconciliation
    |--------------------------------------------------------------------------
    */

    public function test_syncing_an_organization_provisions_eligible_staff_only(): void
    {
        $admin = $this->orgAdmin($this->organization);
        $supervisor = $this->orgUser($this->organization, 'supervisor');
        $agent = $this->orgUser($this->organization, 'agent');
        $viewer = $this->orgUser($this->organization, 'viewer');

        $this->provisioning->syncOrganization($this->organization);

        $this->assertSame(3, Extension::query()->count());
        $this->assertSame(1, Extension::query()->where('user_id', $admin->id)->count());
        $this->assertSame(1, Extension::query()->where('user_id', $supervisor->id)->count());
        $this->assertSame(1, Extension::query()->where('user_id', $agent->id)->count());
        $this->assertSame(0, Extension::query()->where('user_id', $viewer->id)->count());

        Queue::assertPushed(ProvisionTelephonyJob::class, fn (ProvisionTelephonyJob $job) => $job->type === 'provision_organization');
    }

    public function test_the_organization_context_is_stable_and_distinct_per_tenant(): void
    {
        $other = $this->organization();

        $this->provisioning->ensureOrganizationContext($this->organization);
        $context = $this->organization->fresh()->telephony_context;

        $this->assertSame('vodnox_org_'.$this->organization->id, $context);

        $this->provisioning->ensureOrganizationContext($this->organization->fresh());
        $this->assertSame($context, $this->organization->fresh()->telephony_context);

        $this->provisioning->ensureOrganizationContext($other);
        $this->assertNotSame($context, $other->fresh()->telephony_context);
    }

    public function test_ineligible_staff_never_receive_an_asset(): void
    {
        $viewer = $this->orgUser($this->organization, 'viewer');

        $this->assertFalse($this->provisioning->isEligible($viewer));
        $this->assertTrue($this->provisioning->isEligible($this->orgUser($this->organization, 'agent')));
    }

    public function test_a_user_without_an_organization_gets_no_asset(): void
    {
        $orphan = User::create([
            'name' => 'Orphan',
            'username' => 'orphan_'.strtolower(str()->random(6)),
            'email' => strtolower(str()->random(6)).'@vodnox.test',
            'password' => 'password123',
            'role_id' => $this->role('agent')->id,
            'organization_id' => null,
            'active' => true,
        ]);

        $this->assertNull($this->provisioning->ensureExtensionForUser($orphan));
    }
}
