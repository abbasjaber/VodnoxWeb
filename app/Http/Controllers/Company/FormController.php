<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\StoreFieldRequest;
use App\Http\Requests\Forms\StoreFormRequest;
use App\Http\Requests\Forms\UpdateFormRequest;
use App\Models\ActivationRequest;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Services\Forms\FormDefinitionValidator;
use App\Services\Forms\FormPublishService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FormController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly FormDefinitionValidator $validator,
        private readonly FormPublishService $publisher,
    ) {}

    /**
     * Forms landing page. When the add-on is inactive it renders the
     * activation-request state instead of the forms list.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);

        if (! $this->subscriptions->isActive($organization, 'forms')) {
            $pending = ActivationRequest::query()
                ->where('organization_id', $organization->id)
                ->whereHas('feature', fn ($q) => $q->where('code', 'forms'))
                ->where('status', 'pending')
                ->exists();

            return view('company.forms.index', [
                'active' => false,
                'forms' => collect(),
                'pending' => $pending,
                'usage' => 0,
                'limit' => null,
                'search' => null,
                'publishedCount' => 0,
                'totalResponses' => 0,
            ]);
        }

        $search = trim((string) $request->query('q', ''));

        $forms = $organization->forms()
            ->withCount(['submissions', 'fields'])
            ->when($search !== '', fn ($q) => $q->where('title_ar', 'like', "%{$search}%")->orWhere('title_en', 'like', "%{$search}%"))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $subscription = $this->subscriptions->featureSubscription($organization, 'forms');

        $formIds = $organization->forms()->select('id');
        $totalResponses = FormSubmission::query()->whereIn('form_id', $formIds)->count();

        return view('company.forms.index', [
            'active' => true,
            'forms' => $forms,
            'pending' => false,
            'usage' => $this->subscriptions->usage($organization, 'forms', 'forms'),
            'limit' => $subscription?->max_forms,
            'search' => $search,
            'publishedCount' => $organization->forms()->where('status', 'published')->count(),
            'totalResponses' => $totalResponses,
        ]);
    }

    public function store(StoreFormRequest $request)
    {
        $organization = $this->organization($request);

        if (! $this->subscriptions->withinLimit($organization, 'forms', 'forms', 1)) {
            return back()->with('error', __('forms.errors.form_limit_reached'))->withInput();
        }

        $data = $request->validated();

        $form = $organization->forms()->create([
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'] ?? null,
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'thank_you_message_ar' => $data['thank_you_message_ar'] ?? __('forms.defaults.thank_you_ar'),
            'thank_you_message_en' => $data['thank_you_message_en'] ?? __('forms.defaults.thank_you_en'),
            'status' => 'draft',
            'is_active' => true,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'max_submissions' => $data['max_submissions'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('company.forms.builder', $form)
            ->with('status', __('forms.messages.form_created'));
    }

    public function builder(Request $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $form->load(['fields.options', 'fields.conditions']);

        return view('company.forms.builder', [
            'form' => $form,
            'fields' => $form->fields,
            'publishedVersion' => $form->versions()->where('is_published', true)->first(),
            'publicUrl' => route('forms.public.show', $form->public_token),
            'types' => FormDefinitionValidator::TYPES,
            'operators' => FormDefinitionValidator::OPERATORS,
            'actions' => FormDefinitionValidator::ACTIONS,
            'choiceTypes' => FormDefinitionValidator::CHOICE_TYPES,
            'textTypes' => FormDefinitionValidator::TEXT_TYPES,
        ]);
    }

    public function update(UpdateFormRequest $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $data = $request->validated();

        $form->fill([
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'] ?? null,
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'thank_you_message_ar' => $data['thank_you_message_ar'] ?? $form->thank_you_message_ar,
            'thank_you_message_en' => $data['thank_you_message_en'] ?? $form->thank_you_message_en,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'max_submissions' => $data['max_submissions'] ?? null,
        ])->save();

        return back()->with('status', __('forms.messages.form_updated'));
    }

    public function destroy(Request $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $form->delete();

        return redirect()
            ->route('company.forms.index')
            ->with('status', __('forms.messages.form_deleted'));
    }

    public function storeField(StoreFieldRequest $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $data = $request->validated();
        $order = ((int) $form->fields()->max('order')) + 1;

        try {
            $normalized = $this->validator->normalizeFieldDefinition($data, $order);

            DB::transaction(function () use ($form, $normalized) {
                $field = $form->fields()->create($normalized['field']);

                // Validate conditions after the row exists (real id for cycles).
                $this->validator->assertConditionsValid($form, $field, $normalized['conditions']);

                foreach ($normalized['options'] as $option) {
                    $field->options()->create($option);
                }
                foreach ($normalized['conditions'] as $condition) {
                    $field->conditions()->create($condition);
                }
            });
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => __('forms.messages.field_added')]);
        }

        return back()->with('status', __('forms.messages.field_added'));
    }

    public function destroyField(Request $request, Form $form, FormField $field)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        // FormField has no organization_id: authorize through its parent form.
        abort_unless((int) $field->form_id === (int) $form->getKey(), 404);

        $field->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => __('forms.messages.field_removed')]);
        }

        return back()->with('status', __('forms.messages.field_removed'));
    }

    public function publish(Request $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        if ($form->fields()->where('type', '!=', 'section')->count() === 0) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('forms.errors.not_enough_fields')], 422);
            }

            return back()->with('error', __('forms.errors.not_enough_fields'));
        }

        $version = $this->publisher->publish($form);
        $form->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('forms.messages.published'),
                'version' => $version->version,
                'url' => route('forms.public.show', $form->public_token),
            ]);
        }

        return back()->with('status', __('forms.messages.published'));
    }
}
