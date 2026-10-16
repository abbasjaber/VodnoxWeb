<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Customer;
use App\Models\Form;
use App\Models\Organization;
use App\Models\User;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Independent add-on subscriptions (crm / forms / smart_ivr).
 *
 * The gate must be enforced server-side on every data route, and suspending or
 * expiring a subscription must block new work WITHOUT deleting stored data.
 */
class FeatureSubscriptionTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->admin = $this->orgAdmin($this->organization);
    }

    private function customer(Organization $organization): Customer
    {
        return Customer::create([
            'organization_id' => $organization->id,
            'name_ar' => 'عميل',
            'status' => 'active',
        ]);
    }

    private function form(Organization $organization): Form
    {
        return Form::create([
            'organization_id' => $organization->id,
            'title_ar' => 'نموذج',
            'status' => 'draft',
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Hard server-side gate
    // ---------------------------------------------------------------------

    public function test_crm_data_route_is_forbidden_as_json_when_not_subscribed(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('company.crm.customers.index'))
            ->assertForbidden();
    }

    public function test_crm_data_route_redirects_to_landing_when_not_subscribed(): void
    {
        $this->actingAs($this->admin)
            ->get(route('company.crm.customers.index'))
            ->assertRedirect(route('company.crm.index'));
    }

    public function test_forms_store_is_blocked_and_creates_nothing_when_not_subscribed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('company.forms.store'), ['title_ar' => 'نموذج'])
            ->assertRedirect(route('company.forms.index'));

        $this->assertDatabaseCount('forms', 0);
    }

    public function test_data_routes_pass_when_the_addon_is_active(): void
    {
        $this->activateFeature($this->organization, 'crm');

        $this->actingAs($this->admin)
            ->get(route('company.crm.customers.index'))
            ->assertOk();
    }

    public function test_addons_are_independent(): void
    {
        // forms active, crm inactive.
        $this->activateFeature($this->organization, 'forms');

        $this->actingAs($this->admin)
            ->getJson(route('company.crm.customers.index'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('company.forms.index'))
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // Limits
    // ---------------------------------------------------------------------

    public function test_crm_customer_limit_is_enforced_server_side(): void
    {
        $this->activateFeature($this->organization, 'crm', ['max_customers' => 1]);
        $this->customer($this->organization);

        $this->actingAs($this->admin)
            ->post(route('company.crm.customers.store'), [
                'name_ar' => 'Second Customer',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('name_ar');

        $this->assertSame(1, $this->organization->customers()->count());
    }

    public function test_crm_customer_within_limit_is_created(): void
    {
        $this->activateFeature($this->organization, 'crm', ['max_customers' => 2]);

        $this->actingAs($this->admin)
            ->post(route('company.crm.customers.store'), [
                'name_ar' => 'First',
                'status' => 'active',
            ]);

        $this->assertSame(1, $this->organization->customers()->count());
    }

    public function test_forms_limit_is_enforced_server_side(): void
    {
        $this->activateFeature($this->organization, 'forms', ['max_forms' => 1]);
        $this->form($this->organization);

        $this->actingAs($this->admin)
            ->post(route('company.forms.store'), ['title_ar' => 'Second Form'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('forms', 1);
    }

    // ---------------------------------------------------------------------
    // Suspension / expiry must not delete data
    // ---------------------------------------------------------------------

    public function test_suspension_blocks_new_records_without_deleting_existing_data(): void
    {
        $this->activateFeature($this->organization, 'crm');
        $existing = $this->customer($this->organization);

        // Suspend the add-on.
        $this->activateFeature($this->organization, 'crm', ['status' => 'suspended']);

        $this->actingAs($this->admin)
            ->getJson(route('company.crm.customers.index'))
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $existing->id, 'deleted_at' => null]);
    }

    public function test_expired_subscription_period_blocks_access(): void
    {
        $this->activateFeature($this->organization, 'crm', [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('company.crm.customers.index'))
            ->assertForbidden();
    }

    public function test_not_yet_started_subscription_blocks_access(): void
    {
        $this->activateFeature($this->organization, 'crm', [
            'starts_at' => now()->addDay(),
            'ends_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('company.crm.customers.index'))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // R4: communications settings are auto-available (no add-on)
    // ---------------------------------------------------------------------

    public function test_communications_settings_are_available_without_any_addon(): void
    {
        // No crm/forms/smart_ivr subscriptions at all.
        $this->actingAs($this->admin)
            ->get(route('company.communications.index'))
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // R5: smart IVR "coming soon" visibility
    // ---------------------------------------------------------------------

    public function test_smart_ivr_is_forbidden_and_hidden_when_not_subscribed(): void
    {
        $this->actingAs($this->admin)
            ->get(route('company.smart-ivr.index'))
            ->assertForbidden();

        // R5: the add-on must also be absent from the organization navigation.
        $this->actingAs($this->admin)
            ->get(route('company.index'))
            ->assertOk()
            ->assertDontSee(route('company.smart-ivr.index'), false);
    }

    public function test_smart_ivr_is_available_and_listed_when_subscribed(): void
    {
        $this->activateFeature($this->organization, 'smart_ivr');

        $this->actingAs($this->admin)
            ->get(route('company.smart-ivr.index'))
            ->assertOk()
            ->assertSee(__('company.smart_ivr_coming_soon'));

        $this->actingAs($this->admin)
            ->get(route('company.index'))
            ->assertOk()
            ->assertSee(route('company.smart-ivr.index'), false);
    }

    // ---------------------------------------------------------------------
    // Role middleware still applies on top of tenant isolation
    // ---------------------------------------------------------------------

    public function test_agent_role_cannot_reach_company_workspace(): void
    {
        $agent = $this->orgUser($this->organization, 'agent');

        $this->actingAs($agent)
            ->get(route('company.communications.index'))
            ->assertForbidden();
    }
}
