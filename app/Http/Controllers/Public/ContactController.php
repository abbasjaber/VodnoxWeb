<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ContactRequest;
use App\Models\ContactInquiry;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function store(ContactRequest $request)
    {
        $inquiry = ContactInquiry::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'company' => $request->validated('company'),
            'message' => $request->validated('message'),
            'status' => 'new',
        ]);

        $this->audit->log('contact_inquiry.created', $inquiry, [], ['status' => 'new']);

        $this->notifyByMail($inquiry);

        return redirect()
            ->route('contact')
            ->with('contact_success', __('portal.public.contact_success'));
    }

    /**
     * Optional notification. Only attempted when SMTP is configured and never
     * allowed to break the request.
     */
    private function notifyByMail(ContactInquiry $inquiry): void
    {
        $recipient = $this->settings->get('contact_email') ?: config('mail.from.address');
        $mailer = (string) config('mail.default');

        if (! $recipient || $mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return;
        }

        try {
            Mail::raw(
                $inquiry->message,
                fn ($message) => $message
                    ->to($recipient)
                    ->subject('VODNOX — Contact inquiry ('.$inquiry->name.')')
            );
        } catch (\Throwable $exception) {
            Log::warning('Contact inquiry notification could not be sent.', [
                'inquiry_id' => $inquiry->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
