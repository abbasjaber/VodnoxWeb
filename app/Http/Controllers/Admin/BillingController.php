<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvoiceRequest;
use App\Http\Requests\Admin\PaymentRequest;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $query = Invoice::query()->with(['organization', 'subscription', 'payments'])->withCount('payments');

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('invoice_number', 'like', "%{$q}%")
                    ->orWhereHas('organization', function ($org) use ($q) {
                        $org->where('name_ar', 'like', "%{$q}%")
                            ->orWhere('name_en', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    });
            });
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['draft', 'unpaid', 'paid', 'overdue', 'cancelled'], true)) {
                $query->where('status', $status);
            }
        }

        $invoices = $query->latest()->paginate(15)->withQueryString();

        $total = (float) Invoice::sum('amount');
        $collected = (float) Payment::where('status', 'confirmed')->sum('amount');

        $pendingPayments = Payment::where('status', 'pending')
            ->with(['invoice.organization'])
            ->latest()
            ->limit(20)
            ->get();

        return view('admin.billing', [
            'invoices' => $invoices,
            'organizations' => Organization::orderBy('name_ar')->get(),
            'totals' => [
                'total' => $total,
                'collected' => $collected,
                'outstanding' => max(0, $total - $collected),
            ],
            'pendingPayments' => $pendingPayments,
            'q' => $request->query('q', ''),
            'status' => $request->query('status', ''),
        ]);
    }

    public function storeInvoice(InvoiceRequest $request)
    {
        $data = $request->validated();

        if (empty($data['invoice_number'])) {
            $data['invoice_number'] = 'INV-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        }

        if ($data['status'] === 'paid') {
            $data['paid_at'] = now();
        }

        $invoice = Invoice::create($data);

        $this->audit->log('invoice.created', $invoice, [], $invoice->only(['invoice_number', 'amount', 'status']), null, $invoice->organization_id);

        return back()->with('status', __('admin.flash_created'));
    }

    public function updateInvoice(InvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $old = $invoice->only(['amount', 'status', 'due_date', 'currency']);

        if ($data['status'] === 'paid' && ! $invoice->paid_at) {
            $data['paid_at'] = now();
        }

        $invoice->update($data);

        $this->audit->log('invoice.updated', $invoice, $old, $invoice->only(array_keys($old)), null, $invoice->organization_id);

        return back()->with('status', __('admin.flash_updated'));
    }

    public function storePayment(PaymentRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        unset($data['proof']);

        $payment = DB::transaction(function () use ($request, $invoice, $data) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'recorded_by' => auth()->id(),
            ]);

            if ($request->hasFile('proof')) {
                $file = $request->file('proof');
                $path = $file->store('payment-proofs', 'public');

                PaymentProof::create([
                    'payment_id' => $payment->id,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            return $payment;
        });

        // Recording a payment (or its proof) must never mark the invoice as paid.
        $this->audit->log('payment.recorded', $payment, [], [
            'invoice_id' => $invoice->id,
            'amount' => (float) $payment->amount,
            'status' => 'pending',
        ], null, $invoice->organization_id);

        return back()->with('status', __('admin.flash_created'));
    }

    public function confirmPayment(Payment $payment)
    {
        DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'confirmed']);

            $invoice = $payment->invoice;
            if (! $invoice) {
                return;
            }

            $confirmedTotal = (float) $invoice->payments()->where('status', 'confirmed')->sum('amount');

            if ($confirmedTotal >= (float) $invoice->amount && $invoice->status !== 'paid') {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            }
        });

        $this->audit->log('payment.confirmed', $payment, [], ['status' => 'confirmed'], null, $payment->organization_id);

        return back()->with('status', __('admin.flash_status'));
    }

    public function rejectPayment(Payment $payment)
    {
        $payment->update(['status' => 'rejected']);

        $this->audit->log('payment.rejected', $payment, [], ['status' => 'rejected'], null, $payment->organization_id);

        return back()->with('status', __('admin.flash_status'));
    }
}
