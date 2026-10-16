<?php

namespace Tests\Feature\Isolation;

use App\Models\Call;
use App\Models\Customer;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Record-level tenant isolation (R3).
 *
 * Isolation must hold at the record/request/export level, not merely by hiding
 * navigation. Every cross-tenant access below must resolve to 404 so record
 * existence never leaks between organizations.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsTenant;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = $this->organization(['name_ar' => 'Org A', 'name_en' => 'Org A']);
        $this->orgB = $this->organization(['name_ar' => 'Org B', 'name_en' => 'Org B']);

        // Both tenants are fully subscribed, so any denial observed below is
        // record-level isolation and never a subscription gate.
        $this->activateFeature($this->orgA, 'crm');
        $this->activateFeature($this->orgA, 'forms');
        $this->activateFeature($this->orgB, 'crm');
        $this->activateFeature($this->orgB, 'forms');

        $this->adminA = $this->orgAdmin($this->orgA);
        $this->adminB = $this->orgAdmin($this->orgB);
    }

    private function customer(Organization $organization, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $organization->id,
            'name_ar' => 'عميل',
            'name_en' => 'Customer',
            'status' => 'active',
        ], $attributes));
    }

    private function publishedForm(Organization $organization, User $author): array
    {
        $form = Form::create([
            'organization_id' => $organization->id,
            'title_ar' => 'نموذج',
            'title_en' => 'Form',
            'status' => 'draft',
            'is_active' => true,
            'created_by' => $author->id,
        ]);

        $field = FormField::create([
            'form_id' => $form->id,
            'type' => 'short_text',
            'label_ar' => 'الاسم',
            'label_en' => 'Name',
            'is_required' => false,
            'order' => 1,
        ]);

        $version = FormVersion::create([
            'form_id' => $form->id,
            'version' => 1,
            'schema' => [
                'version' => 1,
                'fields' => [[
                    'id' => (int) $field->id,
                    'type' => 'short_text',
                    'label_ar' => 'الاسم',
                    'label_en' => 'Name',
                    'is_required' => false,
                    'order' => 1,
                    'options' => [],
                    'conditions' => [],
                ]],
            ],
            'is_published' => true,
            'published_at' => now(),
        ]);

        $form->update(['status' => 'published']);

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'form_version_id' => $version->id,
            'answers' => [(int) $field->id => 'secret-from-' . $organization->code],
        ]);

        return [$form->refresh(), $submission];
    }

    // ---------------------------------------------------------------------
    // CRM records
    // ---------------------------------------------------------------------

    public function test_customer_list_only_contains_own_organization_records(): void
    {
        $mine = $this->customer($this->orgA, ['name_ar' => 'Mine A']);
        $this->customer($this->orgB, ['name_ar' => 'Theirs B']);

        $response = $this->actingAs($this->adminA)->get(route('company.crm.customers.index'));

        $response->assertOk();
        $response->assertSee('Mine A');
        $response->assertDontSee('Theirs B');
        $this->assertSame(1, Customer::where('organization_id', $this->orgA->id)->count());
        $this->assertNotNull($mine);
    }

    public function test_customer_show_of_another_organization_is_not_found(): void
    {
        $foreign = $this->customer($this->orgB, ['name_ar' => 'Foreign Customer']);

        $this->actingAs($this->adminA)
            ->get(route('company.crm.customers.show', $foreign))
            ->assertNotFound();
    }

    public function test_customer_update_of_another_organization_is_blocked_and_leaves_row_untouched(): void
    {
        $foreign = $this->customer($this->orgB, ['name_ar' => 'Foreign Customer']);

        $this->actingAs($this->adminA)
            ->put(route('company.crm.customers.update', $foreign), [
                'name_ar' => 'Hacked',
                'status' => 'inactive',
            ])
            ->assertNotFound();

        $this->assertSame('Foreign Customer', $foreign->fresh()->name_ar);
    }

    public function test_customer_destroy_of_another_organization_is_blocked_and_row_survives(): void
    {
        $foreign = $this->customer($this->orgB, ['name_ar' => 'Foreign Customer']);

        $this->actingAs($this->adminA)
            ->delete(route('company.crm.customers.destroy', $foreign))
            ->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_note_cannot_be_attached_to_another_organization_customer(): void
    {
        $foreign = $this->customer($this->orgB);

        $this->actingAs($this->adminA)
            ->post(route('company.crm.customers.notes.store', $foreign), ['content' => 'leak'])
            ->assertNotFound();

        $this->assertDatabaseCount('customer_notes', 0);
    }

    public function test_interaction_cannot_be_attached_to_another_organization_customer(): void
    {
        $foreign = $this->customer($this->orgB);

        $this->actingAs($this->adminA)
            ->post(route('company.crm.customers.interactions.store', $foreign), [
                'type' => 'call',
                'content' => 'leak',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('interactions', 0);
    }

    public function test_store_ignores_spoofed_organization_id_in_request_body(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('company.crm.customers.store'), [
                'name_ar' => 'New Customer',
                'status' => 'active',
                // Spoof attempt: must be ignored, tenant comes from the session user.
                'organization_id' => $this->orgB->id,
            ])
            ->assertRedirect();

        $created = Customer::where('name_ar', 'New Customer')->firstOrFail();
        $this->assertSame($this->orgA->id, $created->organization_id);
    }

    // ---------------------------------------------------------------------
    // Calls & recordings
    // ---------------------------------------------------------------------

    public function test_call_show_of_another_organization_is_not_found(): void
    {
        $foreign = Call::create([
            'organization_id' => $this->orgB->id,
            'direction' => 'inbound',
            'status' => 'ended',
        ]);

        $this->actingAs($this->adminA)
            ->get(route('company.calls.show', $foreign))
            ->assertNotFound();
    }

    public function test_recording_list_does_not_leak_other_organization_files(): void
    {
        Recording::create([
            'organization_id' => $this->orgB->id,
            'file_path' => 'recordings/foreign.wav',
            'file_name' => 'foreign-secret.wav',
            'size' => 1024,
        ]);
        Recording::create([
            'organization_id' => $this->orgA->id,
            'file_path' => 'recordings/mine.wav',
            'file_name' => 'mine.wav',
            'size' => 1024,
        ]);

        $this->actingAs($this->adminA)
            ->get(route('company.recordings.index'))
            ->assertOk()
            ->assertSee('mine.wav')
            ->assertDontSee('foreign-secret.wav');
    }

    // ---------------------------------------------------------------------
    // FORMS records, requests and exports
    // ---------------------------------------------------------------------

    public function test_form_builder_of_another_organization_is_not_found(): void
    {
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $this->actingAs($this->adminA)
            ->get(route('company.forms.builder', $foreignForm))
            ->assertNotFound();
    }

    public function test_form_update_of_another_organization_is_not_found(): void
    {
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $this->actingAs($this->adminA)
            ->put(route('company.forms.update', $foreignForm), [
                'title_ar' => 'Hacked',
            ])
            ->assertNotFound();

        $this->assertNotSame('Hacked', $foreignForm->fresh()->title_ar);
    }

    public function test_form_delete_of_another_organization_is_not_found(): void
    {
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $this->actingAs($this->adminA)
            ->delete(route('company.forms.destroy', $foreignForm))
            ->assertNotFound();

        $this->assertDatabaseHas('forms', ['id' => $foreignForm->id, 'deleted_at' => null]);
    }

    public function test_submission_list_of_another_organization_form_is_not_found(): void
    {
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $this->actingAs($this->adminA)
            ->get(route('company.forms.submissions.index', $foreignForm))
            ->assertNotFound();
    }

    public function test_submission_show_rejects_submission_belonging_to_another_form(): void
    {
        [$myForm] = $this->publishedForm($this->orgA, $this->adminA);
        [, $foreignSubmission] = $this->publishedForm($this->orgB, $this->adminB);

        // Own form id + a submission that belongs to another tenant's form.
        $this->actingAs($this->adminA)
            ->get(route('company.forms.submissions.show', [$myForm, $foreignSubmission]))
            ->assertNotFound();
    }

    public function test_export_of_another_organization_form_is_not_found(): void
    {
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $this->actingAs($this->adminA)
            ->get(route('company.forms.submissions.export', $foreignForm))
            ->assertNotFound();
    }

    public function test_export_never_contains_another_organization_submission_data(): void
    {
        [$myForm] = $this->publishedForm($this->orgA, $this->adminA);
        [$foreignForm] = $this->publishedForm($this->orgB, $this->adminB);

        $response = $this->actingAs($this->adminA)
            ->get(route('company.forms.submissions.export', $myForm));

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringNotContainsString('secret-from-' . $this->orgB->code, $body);
        $this->assertStringContainsString('secret-from-' . $this->orgA->code, $body);
        $this->assertSame(1, $foreignForm->submissions()->count());
    }
}
