<?php

namespace Tests\Feature\Admin;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Billing rules (R10): uploading a payment proof must NEVER flip an invoice to
 * "paid"; only an explicit, authorized confirmation of a fully-settled invoice
 * does — and every money action is audited.
 */
class BillingTest extends TestCase
{
    use BuildsTenant;

    private User $superAdmin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->superAdmin = $this->superAdmin();
        $this->organization = $this->organization();
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_number' => 'INV-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'organization_id' => $this->organization->id,
            'amount' => 100,
            'currency' => 'IQD',
            'status' => 'unpaid',
        ], $attributes));
    }

    private function recordPayment(Invoice $invoice, float $amount, bool $withProof = false)
    {
        $payload = [
            'amount' => $amount,
            'currency' => 'IQD',
            'method' => 'bank_transfer',
            'reference' => 'REF-1',
        ];

        if ($withProof) {
            $payload['proof'] = UploadedFile::fake()->image('receipt.jpg');
        }

        return $this->actingAs($this->superAdmin)
            ->post(route('admin.billing.payments.store', $invoice), $payload);
    }

    public function test_recording_a_payment_with_proof_does_not_mark_invoice_paid(): void
    {
        $invoice = $this->invoice();

        $this->recordPayment($invoice, 100, withProof: true)->assertRedirect();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertDatabaseCount('payment_proofs', 1);
    }

    public function test_confirming_a_full_payment_marks_invoice_paid(): void
    {
        $invoice = $this->invoice(['amount' => 100]);
        $this->recordPayment($invoice, 100);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.billing.payments.confirm', $payment))
            ->assertRedirect();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_confirming_a_partial_payment_leaves_invoice_unpaid(): void
    {
        $invoice = $this->invoice(['amount' => 100]);
        $this->recordPayment($invoice, 40);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.billing.payments.confirm', $payment))
            ->assertRedirect();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_rejecting_a_payment_leaves_invoice_unpaid(): void
    {
        $invoice = $this->invoice(['amount' => 100]);
        $this->recordPayment($invoice, 100);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.billing.payments.reject', $payment))
            ->assertRedirect();

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_payment_actions_are_written_to_the_audit_log(): void
    {
        $invoice = $this->invoice();
        $this->recordPayment($invoice, 100);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.recorded',
            'organization_id' => $this->organization->id,
        ]);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.billing.payments.confirm', $payment));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.confirmed',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_creating_a_paid_invoice_sets_paid_at(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.billing.invoices.store'), [
                'organization_id' => $this->organization->id,
                'amount' => 250,
                'currency' => 'IQD',
                'status' => 'paid',
            ])
            ->assertRedirect();

        $invoice = Invoice::where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.created']);
    }
}
