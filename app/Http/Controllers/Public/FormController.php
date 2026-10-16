<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) form pages.
 *
 * Forms are resolved by opaque token only. The organization and any internal
 * identifiers are never exposed to the respondent.
 */
class FormController extends Controller
{
    public function __construct(private readonly FormSubmissionService $submissions) {}

    public function show(string $token)
    {
        $form = Form::where('public_token', $token)->first();

        abort_unless($form, 404);

        if (! $form->isAcceptingSubmissions()) {
            return $this->closedView($form);
        }

        $version = $this->submissions->publishedVersion($form);
        if (! $version) {
            return $this->closedView($form, 'not_published');
        }

        return $this->formView($form, $version->schema, available: true);
    }

    public function submit(Request $request, string $token)
    {
        $form = Form::where('public_token', $token)->first();

        abort_unless($form, 404);

        if (! $form->isAcceptingSubmissions()) {
            return $this->closedView($form);
        }

        try {
            $this->submissions->submit(
                $form,
                (array) $request->input('answers', []),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ValidationException $e) {
            return redirect()
                ->route('forms.public.show', $token)
                ->withErrors($e->errors())
                ->withInput();
        }

        $form->refresh();

        return $this->formView($form, $this->submissions->publishedVersion($form)?->schema, available: true, submitted: true);
    }

    private function formView(Form $form, ?array $schema, bool $available, bool $submitted = false)
    {
        return view('public.form', [
            'form' => $form,
            'schema' => $schema ?? ['version' => 0, 'fields' => []],
            'available' => $available,
            'submitted' => $submitted,
            'reason' => null,
        ]);
    }

    private function closedView(Form $form, ?string $reason = null)
    {
        return view('public.form', [
            'form' => $form,
            'schema' => ['version' => 0, 'fields' => []],
            'available' => false,
            'submitted' => false,
            'reason' => $reason ?? $this->unavailableReason($form),
        ]);
    }

    private function unavailableReason(Form $form): string
    {
        if (! $form->is_active) {
            return 'closed';
        }
        if ($form->status !== 'published') {
            return 'not_published';
        }
        if ($form->starts_at && now()->lt($form->starts_at)) {
            return 'not_started';
        }
        if ($form->ends_at && now()->gt($form->ends_at)) {
            return 'closed';
        }
        if ($form->max_submissions && $form->submissions()->count() >= $form->max_submissions) {
            return 'full';
        }

        return 'closed';
    }
}
