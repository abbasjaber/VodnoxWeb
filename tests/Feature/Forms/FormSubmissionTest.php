<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\FormCondition;
use App\Models\FormField;
use App\Models\Organization;
use App\Models\User;
use App\Services\Forms\FormPublishService;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * VODNOX FORMS behaviour: server-side schema validation, conditional
 * visibility, submission quota (R6), schema versioning and safe CSV export.
 */
class FormSubmissionTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->admin = $this->orgAdmin($this->organization);

        $this->activateFeature($this->organization, 'forms');
    }

    private function form(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'organization_id' => $this->organization->id,
            'title_ar' => 'نموذج',
            'title_en' => 'Form',
            'status' => 'draft',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function field(Form $form, array $attributes): FormField
    {
        $order = ((int) $form->fields()->max('order')) + 1;

        return FormField::create(array_merge([
            'form_id' => $form->id,
            'type' => 'short_text',
            'label_ar' => 'حقل',
            'is_required' => false,
            'order' => $order,
        ], $attributes));
    }

    private function publisher(): FormPublishService
    {
        return app(FormPublishService::class);
    }

    private function submissions(): FormSubmissionService
    {
        return app(FormSubmissionService::class);
    }

    // ---------------------------------------------------------------------
    // R6 — quota
    // ---------------------------------------------------------------------

    public function test_form_level_last_slot_is_not_exceeded(): void
    {
        $form = $this->form(['max_submissions' => 1]);
        $name = $this->field($form, ['label_ar' => 'الاسم']);
        $this->publisher()->publish($form);

        $this->submissions()->submit($form->fresh(), [(int) $name->id => 'Ali'], null, null);

        $this->expectException(ValidationException::class);
        try {
            $this->submissions()->submit($form->fresh(), [(int) $name->id => 'Sara'], null, null);
        } finally {
            $this->assertDatabaseCount('form_submissions', 1);
        }
    }

    public function test_organization_wide_submission_quota_is_enforced(): void
    {
        $this->activateFeature($this->organization, 'forms', ['max_submissions' => 1]);

        $form = $this->form();
        $name = $this->field($form, ['label_ar' => 'الاسم']);
        $this->publisher()->publish($form);

        $this->submissions()->submit($form->fresh(), [(int) $name->id => 'Ali'], null, null);

        $this->expectException(ValidationException::class);
        try {
            $this->submissions()->submit($form->fresh(), [(int) $name->id => 'Sara'], null, null);
        } finally {
            $this->assertDatabaseCount('form_submissions', 1);
        }
    }

    // ---------------------------------------------------------------------
    // Schema validation
    // ---------------------------------------------------------------------

    public function test_required_field_is_enforced(): void
    {
        $form = $this->form();
        $this->field($form, ['label_ar' => 'الاسم', 'is_required' => true]);
        $this->publisher()->publish($form);

        $this->expectException(ValidationException::class);
        $this->submissions()->submit($form->fresh(), [], null, null);
    }

    public function test_hidden_conditionally_field_is_excluded_from_required_validation(): void
    {
        $form = $this->form();
        $trigger = $this->field($form, [
            'type' => 'radio',
            'label_ar' => 'هل توافق؟',
            'is_required' => true,
        ]);
        $trigger->options()->createMany([
            ['label_ar' => 'نعم', 'value' => 'yes', 'order' => 1],
            ['label_ar' => 'لا', 'value' => 'no', 'order' => 2],
        ]);

        $detail = $this->field($form, ['label_ar' => 'التفاصيل', 'is_required' => true]);
        FormCondition::create([
            'form_field_id' => $detail->id,
            'depends_on_field_id' => $trigger->id,
            'operator' => 'equals',
            'value' => 'yes',
            'action' => 'show',
        ]);

        $this->publisher()->publish($form);

        // Trigger = no => detail stays hidden => its required flag must not fire.
        $submission = $this->submissions()->submit(
            $form->fresh(),
            [(int) $trigger->id => 'no'],
            null,
            null,
        );

        $answers = $submission->answers;
        $this->assertArrayHasKey((int) $trigger->id, $answers);
        $this->assertArrayNotHasKey((int) $detail->id, $answers);
    }

    public function test_visible_conditionally_field_becomes_required(): void
    {
        $form = $this->form();
        $trigger = $this->field($form, ['type' => 'radio', 'label_ar' => 'هل توافق؟']);
        $trigger->options()->createMany([
            ['label_ar' => 'نعم', 'value' => 'yes', 'order' => 1],
            ['label_ar' => 'لا', 'value' => 'no', 'order' => 2],
        ]);
        $detail = $this->field($form, ['label_ar' => 'التفاصيل', 'is_required' => true]);
        FormCondition::create([
            'form_field_id' => $detail->id,
            'depends_on_field_id' => $trigger->id,
            'operator' => 'equals',
            'value' => 'yes',
            'action' => 'show',
        ]);

        $this->publisher()->publish($form);

        $this->expectException(ValidationException::class);
        $this->submissions()->submit($form->fresh(), [(int) $trigger->id => 'yes'], null, null);
    }

    public function test_invalid_option_is_rejected(): void
    {
        $form = $this->form();
        $field = $this->field($form, ['type' => 'select', 'label_ar' => 'المدينة']);
        $field->options()->createMany([
            ['label_ar' => 'بغداد', 'value' => 'baghdad', 'order' => 1],
        ]);
        $this->publisher()->publish($form);

        $this->expectException(ValidationException::class);
        $this->submissions()->submit($form->fresh(), [(int) $field->id => 'basra'], null, null);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $form = $this->form();
        $field = $this->field($form, ['type' => 'email', 'label_ar' => 'البريد']);
        $this->publisher()->publish($form);

        $this->expectException(ValidationException::class);
        $this->submissions()->submit($form->fresh(), [(int) $field->id => 'not-an-email'], null, null);
    }

    public function test_submission_to_unpublished_form_is_rejected(): void
    {
        $form = $this->form();
        $field = $this->field($form, ['label_ar' => 'الاسم']);

        $this->expectException(ValidationException::class);
        $this->submissions()->submit($form, [(int) $field->id => 'Ali'], null, null);
    }

    // ---------------------------------------------------------------------
    // Publishing & schema versioning
    // ---------------------------------------------------------------------

    public function test_publishing_generates_an_opaque_unique_token(): void
    {
        $formA = $this->form();
        $this->field($formA, ['label_ar' => 'A']);
        $this->publisher()->publish($formA);

        $formB = $this->form();
        $this->field($formB, ['label_ar' => 'B']);
        $this->publisher()->publish($formB);

        $formA->refresh();
        $formB->refresh();

        $this->assertSame(32, strlen($formA->public_token));
        $this->assertFalse(ctype_digit($formA->public_token));
        $this->assertNotSame($formA->public_token, $formB->public_token);
        $this->assertSame('published', $formA->status);
    }

    public function test_editing_a_field_after_publish_does_not_break_old_submissions(): void
    {
        $form = $this->form();
        $field = $this->field($form, ['label_ar' => 'الاسم الأصلي']);
        $this->publisher()->publish($form);

        $submission = $this->submissions()->submit($form->fresh(), [(int) $field->id => 'Ali'], null, null);

        // Edit the live field, then publish a new version.
        $field->update(['label_ar' => 'اسم معدل']);
        $this->publisher()->publish($form->fresh());

        $version = $submission->fresh()->version;
        $this->assertNotNull($version);
        $snapshotLabel = $version->schema['fields'][0]['label_ar'] ?? null;

        $this->assertSame('الاسم الأصلي', $snapshotLabel);

        $latest = $this->submissions()->publishedVersion($form->fresh());
        $this->assertSame('اسم معدل', $latest->schema['fields'][0]['label_ar']);
    }

    // ---------------------------------------------------------------------
    // Public page + safe export
    // ---------------------------------------------------------------------

    public function test_public_page_is_reachable_by_token_without_leaking_the_organization(): void
    {
        $form = $this->form();
        $this->field($form, ['label_ar' => 'الاسم']);
        $this->publisher()->publish($form);
        $form->refresh();

        $response = $this->get(route('forms.public.show', $form->public_token));

        $response->assertOk();
        // The opaque token is the only handle; no internal tenant identity leaks.
        $response->assertDontSee($this->organization->code);
        $response->assertDontSee('organization_id');
    }

    public function test_unknown_token_returns_not_found(): void
    {
        $this->get(route('forms.public.show', 'this-token-does-not-exist'))->assertNotFound();
    }

    public function test_export_has_utf8_bom_and_neutralizes_formula_injection(): void
    {
        $form = $this->form();
        $field = $this->field($form, ['label_ar' => 'ملاحظة']);
        $this->publisher()->publish($form);

        $this->submissions()->submit($form->fresh(), [(int) $field->id => '=SUM(1,1)'], null, null);

        $response = $this->actingAs($this->admin)
            ->get(route('company.forms.submissions.export', $form->fresh()));

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("'=SUM(1,1)", $body);
    }
}
