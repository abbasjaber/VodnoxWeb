<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormSubmissionController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, Form $form)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $from = $request->query('from');
        $to = $request->query('to');

        $submissions = $form->submissions()
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $fields = $form->fields()->where('type', '!=', 'section')->get();

        return view('company.forms.submissions', [
            'form' => $form,
            'submissions' => $submissions,
            'fields' => $fields,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function show(Request $request, Form $form, FormSubmission $submission)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        // FormSubmission has no organization_id: authorize through its parent form.
        abort_unless((int) $submission->form_id === (int) $form->getKey(), 404);

        $fields = $form->fields()->with('options')->get();

        // Prefer the frozen schema of the submission's version so old responses
        // keep their original labels even after the form was edited.
        $schema = $submission->version?->schema;
        $schemaFields = $schema['fields'] ?? $fields->map(fn ($field) => [
            'id' => $field->id,
            'type' => $field->type,
            'label_ar' => $field->label_ar,
            'label_en' => $field->label_en,
            'options' => $field->options->map(fn ($option) => ['value' => $option->value, 'label_ar' => $option->label_ar, 'label_en' => $option->label_en])->all(),
        ])->all();

        return view('company.forms.submission-show', [
            'form' => $form,
            'submission' => $submission,
            'schemaFields' => $schemaFields,
        ]);
    }

    /**
     * Streamed CSV export with a UTF-8 BOM (so Arabic renders in Excel) and
     * formula-injection protection on every cell.
     */
    public function export(Request $request, Form $form): StreamedResponse
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $form);

        $fields = $form->fields()->where('type', '!=', 'section')->orderBy('order')->get();
        $timezone = 'Asia/Baghdad';
        $filename = __('forms.export.filename').'-'.$form->getKey().'.csv';

        return response()->streamDownload(function () use ($form, $fields, $timezone) {
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Excel (Arabic support).
            fwrite($output, "\xEF\xBB\xBF");

            $headers = array_merge(
                [__('forms.export.id'), __('forms.export.received')],
                $fields->map(fn ($field) => $this->localizedLabel($field->label_ar, $field->label_en))->all(),
            );
            fputcsv($output, array_map([$this, 'csvCell'], $headers));

            $form->submissions()->orderBy('id')->chunk(200, function ($submissions) use ($output, $fields, $timezone) {
                foreach ($submissions as $submission) {
                    $answers = $submission->answers ?? [];

                    $row = [
                        $submission->getKey(),
                        $submission->created_at?->timezone($timezone)->format('Y-m-d H:i') ?? '',
                    ];

                    foreach ($fields as $field) {
                        $value = $answers[$field->id] ?? ($answers[(string) $field->id] ?? '');
                        $row[] = is_array($value) ? implode(' | ', $value) : (string) $value;
                    }

                    fputcsv($output, array_map([$this, 'csvCell'], $row));
                }
            });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prefix any cell that could be interpreted as a formula so spreadsheets
     * never execute it.
     */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'".$value;
        }

        return $value;
    }

    private function localizedLabel(?string $ar, ?string $en): string
    {
        return app()->getLocale() === 'ar' ? ($ar ?: $en ?: '') : ($en ?: $ar ?: '');
    }
}
