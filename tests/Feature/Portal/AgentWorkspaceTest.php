<?php

namespace Tests\Feature\Portal;

use App\Models\Call;
use App\Models\CallNote;
use App\Models\CallWrapup;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Followup;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Direct coverage of the agent workspaces (portal): /user/incoming and
 * /user/reception.
 *
 * These pages share one controller (ReceptionController extends
 * IncomingController). The behaviours verified here are:
 *   - access rules (guest, agent, super admin without tenant, inactive org);
 *   - session lifecycle (start / block while a draft wrap-up is open);
 *   - persistence of notes, complaints, follow-ups and the wrap-up;
 *   - the wrap-up draft is never lost while the session is still open;
 *   - the record-level customer scope (a foreign customer_id is rejected).
 */
class AgentWorkspaceTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->agent = $this->orgUser($this->organization, 'agent', ['extension' => '2001']);
    }

    private function openSession(string $route = 'user.incoming.store'): void
    {
        $this->actingAs($this->agent)
            ->post(route($route), ['action' => 'start'])
            ->assertRedirect(route('user.incoming.index'))
            ->assertSessionHasNoErrors();
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $this->organization->id,
            'name_ar' => 'عميل اختبار',
            'name_en' => 'Test Customer',
            'phone' => '+9647700000001',
            'status' => 'active',
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Access rules
    |--------------------------------------------------------------------------
    */

    public function test_guest_is_redirected_from_the_agent_workspaces(): void
    {
        $this->get(route('user.incoming.index'))->assertRedirect(route('login'));
        $this->get(route('user.reception.index'))->assertRedirect(route('login'));
        $this->post(route('user.incoming.store'), ['action' => 'start'])->assertRedirect(route('login'));
    }

    public function test_agent_can_open_both_workspaces(): void
    {
        $this->actingAs($this->agent)->get(route('user.incoming.index'))->assertOk();
        $this->actingAs($this->agent)->get(route('user.reception.index'))->assertOk();
    }

    public function test_super_admin_without_tenant_is_forbidden(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->get(route('user.incoming.index'))->assertForbidden();
        $this->actingAs($superAdmin)->post(route('user.incoming.store'), ['action' => 'start'])->assertForbidden();
    }

    public function test_user_of_inactive_organization_is_forbidden(): void
    {
        $suspended = $this->organization(['status' => 'suspended']);
        $user = $this->orgUser($suspended, 'agent');

        $this->actingAs($user)->get(route('user.incoming.index'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_start_creates_a_local_session_call(): void
    {
        $this->openSession();

        $call = Call::query()->where('user_id', $this->agent->id)->sole();

        $this->assertStringStartsWith('local-session:', $call->external_call_id);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('answered', $call->status);
        $this->assertSame('2001', $call->callee_number);
        $this->assertSame($this->organization->id, $call->organization_id);
        $this->assertNotNull($call->started_at);
    }

    public function test_starting_a_second_session_is_refused_while_one_is_active(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'start'])
            ->assertSessionHasErrors('action');

        $this->assertSame(1, Call::query()->where('user_id', $this->agent->id)->count());
    }

    public function test_session_actions_require_an_open_session(): void
    {
        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'note', 'content' => 'بدون جلسة'])
            ->assertSessionHasErrors('action');

        $this->assertSame(0, CallNote::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Notes, complaints and follow-ups
    |--------------------------------------------------------------------------
    */

    public function test_note_is_persisted_on_the_active_session(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'note', 'content' => 'ملاحظة مهمة'])
            ->assertRedirect(route('user.incoming.index'))
            ->assertSessionHasNoErrors();

        $note = CallNote::query()->sole();
        $this->assertSame('ملاحظة مهمة', $note->content);
        $this->assertSame($this->agent->id, $note->user_id);
        $this->assertSame($this->organization->id, $note->organization_id);
    }

    public function test_note_without_content_is_rejected(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'note'])
            ->assertSessionHasErrors('content');

        $this->assertSame(0, CallNote::query()->count());
    }

    public function test_complaint_is_persisted_with_priority_and_assignee(): void
    {
        $this->openSession();
        $department = Department::create([
            'organization_id' => $this->organization->id,
            'name_ar' => 'الدعم',
            'name_en' => 'Support',
        ]);

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'complaint',
                'subject' => 'تأخر الرد',
                'description' => 'لم يتم الرد خلال 48 ساعة',
                'priority' => 'high',
                'department_id' => $department->id,
                'assigned_to' => $this->agent->id,
            ])
            ->assertSessionHasNoErrors();

        $complaint = Complaint::query()->sole();
        $this->assertSame('تأخر الرد', $complaint->subject);
        $this->assertSame('high', $complaint->priority);
        $this->assertSame('open', $complaint->status);
        $this->assertSame($department->id, $complaint->department_id);
        $this->assertSame($this->agent->id, $complaint->assigned_to);
    }

    public function test_complaint_requires_subject_and_description(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'complaint', 'subject' => 'بلا وصف'])
            ->assertSessionHasErrors('subject');

        $this->assertSame(0, Complaint::query()->count());
    }

    public function test_followup_is_persisted_and_due_date_is_stored_in_utc(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'followup',
                'title' => 'متابعة العميل',
                'description' => 'الاتصال مرة أخرى',
                'due_at' => '2026-10-20 15:00:00',
            ])
            ->assertSessionHasNoErrors();

        $followup = Followup::query()->sole();
        $expectedUtc = Carbon::parse('2026-10-20 15:00:00', 'Asia/Baghdad')->utc()->format('Y-m-d H:i:s');

        $this->assertSame('متابعة العميل', $followup->title);
        $this->assertSame('pending', $followup->status);
        $this->assertSame($expectedUtc, $followup->getRawOriginal('due_at'));
    }

    public function test_followup_requires_a_title(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'followup'])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, Followup::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Wrap-up: draft is kept, completion closes the session
    |--------------------------------------------------------------------------
    */

    public function test_wrapup_draft_is_saved_and_survives_a_reload(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'wrapup',
                'outcome' => 'callback',
                'summary' => 'مسودة مؤقتة',
            ])
            ->assertSessionHasNoErrors();

        $wrapup = CallWrapup::query()->sole();
        $this->assertFalse($wrapup->is_completed);

        // The draft must still be surfaced after a full page reload.
        $response = $this->actingAs($this->agent)->get(route('user.incoming.index'))->assertOk();
        $this->assertNotNull($response->viewData('session'));
        $this->assertNotNull($response->viewData('draftWrapup'));
        $this->assertFalse($response->viewData('draftWrapup')->is_completed);
    }

    public function test_starting_a_new_session_is_blocked_while_a_draft_wrapup_is_open(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'wrapup', 'summary' => 'مسودة مفتوحة'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'start'])
            ->assertSessionHasErrors('action');

        $this->assertSame(1, Call::query()->where('user_id', $this->agent->id)->count());
    }

    public function test_finishing_a_session_requires_outcome_and_summary(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), ['action' => 'finish', 'outcome' => 'resolved'])
            ->assertSessionHasErrors('summary');
    }

    public function test_finishing_a_session_completes_the_wrapup_and_allows_a_new_one(): void
    {
        $this->openSession();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'finish',
                'outcome' => 'resolved',
                'summary' => 'تم حل المشكلة',
            ])
            ->assertSessionHasNoErrors();

        $wrapup = CallWrapup::query()->sole();
        $this->assertTrue($wrapup->is_completed);
        $this->assertSame('resolved', $wrapup->outcome);

        $call = Call::query()->where('user_id', $this->agent->id)->sole();
        $this->assertNotNull($call->ended_at);

        // A finished session no longer blocks a new one.
        $this->openSession();
        $this->assertSame(2, Call::query()->where('user_id', $this->agent->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Record-level isolation
    |--------------------------------------------------------------------------
    */

    public function test_foreign_customer_id_is_rejected(): void
    {
        $this->openSession();

        $otherOrg = $this->organization();
        $foreignCustomer = Customer::create([
            'organization_id' => $otherOrg->id,
            'name_ar' => 'عميل مؤسسة أخرى',
            'name_en' => 'Foreign Customer',
            'status' => 'active',
        ]);

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'note',
                'content' => 'ملاحظة',
                'customer_id' => $foreignCustomer->id,
            ])
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, CallNote::query()->count());
    }

    public function test_own_customer_can_be_attached_to_a_note(): void
    {
        $this->openSession();
        $customer = $this->customer();

        $this->actingAs($this->agent)
            ->post(route('user.incoming.store'), [
                'action' => 'note',
                'content' => 'ملاحظة مرتبطة بعميل',
                'customer_id' => $customer->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($customer->id, CallNote::query()->sole()->customer_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Reception workspace parity
    |--------------------------------------------------------------------------
    */

    public function test_reception_workspace_persists_its_own_session_and_note(): void
    {
        $this->actingAs($this->agent)
            ->post(route('user.reception.store'), ['action' => 'start', 'caller_number' => '+9647700000009'])
            ->assertRedirect(route('user.reception.index'))
            ->assertSessionHasNoErrors();

        $call = Call::query()->where('user_id', $this->agent->id)->sole();
        $this->assertStringStartsWith('local-session:', $call->external_call_id);
        $this->assertSame('+9647700000009', $call->caller_number);

        $this->actingAs($this->agent)
            ->post(route('user.reception.store'), ['action' => 'note', 'content' => 'ملاحظة الاستقبال'])
            ->assertRedirect(route('user.reception.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('ملاحظة الاستقبال', CallNote::query()->sole()->content);
    }
}
