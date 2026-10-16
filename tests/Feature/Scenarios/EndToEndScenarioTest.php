<?php

namespace Tests\Feature\Scenarios;

use App\Models\Customer;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\OrganizationFeatureSubscription;
use App\Models\RoutingSchedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Whole-flow scenarios across the three workspaces (super admin -> organization
 * admin -> agent), driven through the real HTTP routes and requests rather than
 * only through services.
 *
 * Each scenario walks a chain that a real operator would walk, and asserts both
 * the HTTP outcome and the persisted rows, so a green test means the buttons
 * actually did something.
 */
class EndToEndScenarioTest extends TestCase
{
    use BuildsTenant;

    /*
    |--------------------------------------------------------------------------
    | Scenario 1 — platform creates an organization, its admin and its staff
    |--------------------------------------------------------------------------
    */

    public function test_super_admin_creates_organization_and_admin_then_admin_adds_staff(): void
    {
        $this->role('super_admin');
        $this->role('organization_admin');
        $this->role('agent');

        $super = $this->superAdmin();

        // Super admin provisions a brand-new organization together with its
        // first administrator in a single request.
        $this->actingAs($super)
            ->post(route('admin.organizations.store'), [
                'code' => 'REVIEW-01',
                'name_ar' => 'مؤسسة المراجعة',
                'name_en' => 'Review Organization',
                'status' => 'active',
                'language' => 'ar',
                'admin_name' => 'مدير المؤسسة',
                'admin_username' => 'orgadmin01',
                'admin_email' => 'orgadmin01@vodnox.test',
                'admin_password' => 'OrgAdmin#2026',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $organization = Organization::query()->where('code', 'REVIEW-01')->sole();
        $this->assertSame('active', $organization->status);
        $this->assertNotNull($organization->activated_at);

        $admin = User::query()->where('username', 'orgadmin01')->sole();
        $this->assertSame($organization->id, $admin->organization_id);
        $this->assertSame('organization_admin', $admin->role->name);
        $this->assertTrue(Hash::check('OrgAdmin#2026', $admin->password));

        // The organization admin signs in and adds two agents.
        $agentRole = $this->role('agent');

        foreach ([['agent01', '1001'], ['agent02', '1002']] as [$username, $extension]) {
            $this->actingAs($admin)
                ->post(route('company.team.store'), [
                    'name' => 'موظف '.$username,
                    'username' => $username,
                    'email' => $username.'@vodnox.test',
                    'role_id' => $agentRole->id,
                    'extension' => $extension,
                    'active' => 1,
                    'password' => 'Agent#2026',
                    'password_confirmation' => 'Agent#2026',
                ])
                ->assertRedirect(route('company.team.index'))
                ->assertSessionHasNoErrors();
        }

        // admin + two agents, all inside the new organization.
        $this->assertSame(3, $organization->users()->count());
        $this->assertSame(
            2,
            $organization->users()->whereHas('role', fn ($q) => $q->where('name', 'agent'))->count(),
        );

        $agents = $organization->users()->whereHas('role', fn ($q) => $q->where('name', 'agent'))->get();
        $this->assertTrue($agents->every(fn (User $u) => $u->organization_id === $organization->id));
    }

    public function test_organization_admin_cannot_grant_the_super_admin_role(): void
    {
        $this->role('organization_admin');
        $this->role('super_admin');
        $this->role('agent');

        $organization = $this->organization();
        $admin = $this->orgAdmin($organization);

        $this->actingAs($admin)
            ->post(route('company.team.store'), [
                'name' => 'متسلل',
                'username' => 'intruder',
                'email' => 'intruder@vodnox.test',
                'role_id' => $this->role('super_admin')->id,
                'password' => 'Agent#2026',
                'password_confirmation' => 'Agent#2026',
            ])
            ->assertSessionHasErrors('role_id');

        $this->assertNull(User::query()->where('username', 'intruder')->first());
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario 2 — independent add-ons: activation gates, suspension re-locks
    |--------------------------------------------------------------------------
    */

    public function test_crm_addon_is_gated_then_activated_then_suspended(): void
    {
        $this->role('super_admin');
        $this->role('organization_admin');

        $organization = $this->organization();
        $admin = $this->orgAdmin($organization);
        $super = $this->superAdmin();

        // Inactive: the landing page shows the request state; data routes bounce
        // the browser back to the landing page (hard-gated server-side).
        $this->actingAs($admin)->get(route('company.crm.index'))->assertOk();
        $this->actingAs($admin)->get(route('company.crm.customers.index'))
            ->assertRedirect(route('company.crm.index'));

        // Super admin activates the CRM add-on.
        $this->actingAs($super)
            ->post(route('admin.features.store', 'crm'), [
                'organization_id' => $organization->id,
                'status' => 'active',
                'starts_at' => now()->subDay()->toDateTimeString(),
                'currency' => 'IQD',
            ])
            ->assertSessionHasNoErrors();

        $subscription = OrganizationFeatureSubscription::query()
            ->where('organization_id', $organization->id)
            ->whereHas('feature', fn ($q) => $q->where('code', 'crm'))
            ->sole();
        $this->assertTrue($subscription->isActive());

        // Active: data routes open.
        $this->actingAs($admin)->get(route('company.crm.customers.index'))->assertOk();

        // Suspended: data routes lock again, record data is untouched.
        $this->actingAs($super)
            ->put(route('admin.features.update', $subscription), [
                'organization_id' => $organization->id,
                'status' => 'suspended',
                'currency' => 'IQD',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($subscription->fresh()->isActive());
        $this->actingAs($admin)->get(route('company.crm.customers.index'))
            ->assertRedirect(route('company.crm.index'));
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario 3 — CRM chain: customer -> opportunity -> task
    |--------------------------------------------------------------------------
    */

    public function test_customer_opportunity_and_task_are_created_in_sequence(): void
    {
        $this->role('organization_admin');

        $organization = $this->organization();
        $admin = $this->orgAdmin($organization);
        $this->activateFeature($organization, 'crm');

        // Customer.
        $this->actingAs($admin)
            ->post(route('company.crm.customers.store'), [
                'name_ar' => 'شركة النور',
                'name_en' => 'Al-Noor Co',
                'phone' => '+9647701112233',
                'status' => 'active',
            ])
            ->assertRedirect(route('company.crm.customers.index'))
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame('شركة النور', $customer->name_ar);

        // Opportunity linked to that customer.
        $this->actingAs($admin)
            ->post(route('company.crm.opportunities.store'), [
                'customer_id' => $customer->id,
                'title' => 'تجديد العقد السنوي',
                'value' => 1500000,
                'currency' => 'IQD',
                'status' => 'open',
            ])
            ->assertRedirect(route('company.crm.opportunities.index'))
            ->assertSessionHasNoErrors();

        $opportunity = Opportunity::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($customer->id, $opportunity->customer_id);
        $this->assertNotNull($opportunity->stage_id);

        // Task tied to both.
        $this->actingAs($admin)
            ->post(route('company.crm.tasks.store'), [
                'customer_id' => $customer->id,
                'opportunity_id' => $opportunity->id,
                'title' => 'إرسال العرض السعري',
                'status' => 'pending',
                'priority' => 'high',
                'due_at' => '2026-10-25 10:00:00',
            ])
            ->assertRedirect(route('company.crm.tasks.index'))
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($opportunity->id, $task->opportunity_id);
        $this->assertSame('high', $task->priority);
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario 4 — publish a form, then answer it from an independent session
    |--------------------------------------------------------------------------
    */

    public function test_form_is_published_and_answered_from_an_independent_session(): void
    {
        $this->role('organization_admin');

        $organization = $this->organization();
        $admin = $this->orgAdmin($organization);
        $this->activateFeature($organization, 'forms');

        // 1. Create the form.
        $this->actingAs($admin)
            ->post(route('company.forms.store'), [
                'title_ar' => 'نموذج رضا العملاء',
                'title_en' => 'Customer Satisfaction',
            ])
            ->assertSessionHasNoErrors();

        $form = Form::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame('draft', $form->status);

        // 2. Add a required field.
        $this->actingAs($admin)
            ->post(route('company.forms.fields.store', $form), [
                'type' => 'short_text',
                'label_ar' => 'الاسم الكامل',
                'is_required' => 1,
            ])
            ->assertSessionHasNoErrors();

        $field = FormField::query()->where('form_id', $form->id)->sole();

        // 3. Publish -> an opaque token is generated.
        $this->actingAs($admin)
            ->post(route('company.forms.publish', $form))
            ->assertSessionHasNoErrors();

        $form->refresh();
        $this->assertSame('published', $form->status);
        $this->assertSame(32, strlen((string) $form->public_token));

        // 4. Respond from a completely independent (anonymous) session.
        Auth::logout();
        $this->flushSession();

        $this->get(route('forms.public.show', $form->public_token))
            ->assertOk()
            ->assertDontSee($organization->code);

        $this->post(route('forms.public.submit', $form->public_token), [
            'answers' => [(string) $field->id => 'علي حسن'],
        ])->assertOk();

        $this->assertSame(1, FormSubmission::query()->where('form_id', $form->id)->count());
        $submission = FormSubmission::query()->where('form_id', $form->id)->sole();
        $this->assertSame('علي حسن', $submission->answers[(string) $field->id]
            ?? $submission->answers[(int) $field->id]);

        // 5. The organization admin sees the response in the submissions list.
        $this->actingAs($admin)
            ->get(route('company.forms.submissions.index', $form))
            ->assertOk();
    }

    public function test_form_submission_quota_uses_the_configured_test_limit(): void
    {
        $this->role('organization_admin');

        $organization = $this->organization();
        $admin = $this->orgAdmin($organization);
        $this->activateFeature($organization, 'forms', ['max_submissions' => 1]);

        $this->actingAs($admin)
            ->post(route('company.forms.store'), ['title_ar' => 'نموذج محدود'])
            ->assertSessionHasNoErrors();

        $form = Form::query()->where('organization_id', $organization->id)->sole();

        $this->actingAs($admin)
            ->post(route('company.forms.fields.store', $form), ['type' => 'short_text', 'label_ar' => 'ملاحظة'])
            ->assertSessionHasNoErrors();

        $field = FormField::query()->where('form_id', $form->id)->sole();

        $this->actingAs($admin)->post(route('company.forms.publish', $form))->assertSessionHasNoErrors();
        $form->refresh();

        Auth::logout();
        $this->flushSession();

        $this->post(route('forms.public.submit', $form->public_token), [
            'answers' => [(string) $field->id => 'الرد الأول'],
        ])->assertOk();

        // The second response must be refused — the quota is one.
        $this->post(route('forms.public.submit', $form->public_token), [
            'answers' => [(string) $field->id => 'الرد الثاني'],
        ])
            ->assertRedirect(route('forms.public.show', $form->public_token))
            ->assertSessionHasErrors();

        $this->assertSame(1, FormSubmission::query()->where('form_id', $form->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario 5 — night routing schedule and its simulation route
    |--------------------------------------------------------------------------
    */

    public function test_night_schedule_is_created_and_its_route_matches_at_night(): void
    {
        $this->role('organization_admin');
        $this->role('agent');

        $organization = $this->organization(['timezone' => 'Asia/Baghdad']);
        $admin = $this->orgAdmin($organization);
        $agent = $this->orgUser($organization, 'agent', ['extension' => '3001']);

        // Saturday (day 6) 22:00 -> 02:00: a window that crosses midnight.
        $this->actingAs($admin)
            ->post(route('company.communications.store'), [
                'name_ar' => 'الوردية الليلية',
                'name_en' => 'Night Shift',
                'strategy' => 'sequential',
                'is_active' => 1,
                'ring_duration' => 30,
                'no_answer_type' => 'voicemail',
                'outside_hours_type' => 'voicemail',
                'days' => [
                    ['day' => 6, 'start_time' => '22:00', 'end_time' => '02:00'],
                ],
                'targets' => [
                    ['user_id' => $agent->id],
                ],
            ])
            ->assertRedirect(route('company.communications.index'))
            ->assertSessionHasNoErrors();

        $schedule = RoutingSchedule::query()->where('organization_id', $organization->id)->sole();
        $this->assertTrue((bool) $schedule->is_active);
        $this->assertSame(1, $schedule->days()->count());
        $this->assertSame(1, $schedule->targets()->count());

        // Saturday 23:30 Baghdad -> inside the window (matched).
        $this->actingAs($admin)
            ->postJson(route('company.communications.test', $schedule), [
                'datetime' => '2026-10-17T23:30:00+03:00',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('matched.id', $schedule->id)
            ->assertJsonPath('targets.0.id', $agent->id);

        // Sunday 01:00 Baghdad -> still inside the midnight-crossing window.
        $this->actingAs($admin)
            ->postJson(route('company.communications.test', $schedule), [
                'datetime' => '2026-10-18T01:00:00+03:00',
            ])
            ->assertOk()
            ->assertJsonPath('matched.id', $schedule->id);

        // Wednesday 23:30 Baghdad -> outside the window (no match + fallback).
        $this->actingAs($admin)
            ->postJson(route('company.communications.test', $schedule), [
                'datetime' => '2026-10-14T23:30:00+03:00',
            ])
            ->assertOk()
            ->assertJsonPath('matched', null)
            ->assertJsonPath('outside_hours_type', 'voicemail');
    }

    public function test_overlapping_night_windows_are_rejected_by_the_request(): void
    {
        $this->role('organization_admin');
        $this->role('agent');

        $organization = $this->organization(['timezone' => 'Asia/Baghdad']);
        $admin = $this->orgAdmin($organization);
        $agent = $this->orgUser($organization, 'agent');

        // Sat 22:00-02:00 and Sun 01:00-03:00 overlap after midnight.
        $this->actingAs($admin)
            ->post(route('company.communications.store'), [
                'name_ar' => 'متداخل',
                'name_en' => 'Overlap',
                'strategy' => 'sequential',
                'is_active' => 1,
                'ring_duration' => 30,
                'no_answer_type' => 'voicemail',
                'outside_hours_type' => 'voicemail',
                'days' => [
                    ['day' => 6, 'start_time' => '22:00', 'end_time' => '02:00'],
                    ['day' => 0, 'start_time' => '01:00', 'end_time' => '03:00'],
                ],
                'targets' => [
                    ['user_id' => $agent->id],
                ],
            ])
            ->assertSessionHasErrors('days.1.day');

        $this->assertSame(0, RoutingSchedule::query()->where('organization_id', $organization->id)->count());
    }
}
