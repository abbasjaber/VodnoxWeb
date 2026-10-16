<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CustomerNoteRequest;
use App\Http\Requests\Crm\CustomerRequest;
use App\Http\Requests\Crm\InteractionRequest;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Interaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        $organization = $this->organization($request);

        $query = Customer::query()
            ->where('organization_id', $organization->id)
            ->with('assignedTo:id,name');

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->search($term);
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $customers = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('company.crm.customers', [
            'organization' => $organization,
            'customers' => $customers,
            'users' => $this->assignableUsers($organization),
            'filters' => ['q' => $term, 'status' => $status],
            'customerLimitReached' => ! $this->subscriptions->withinLimit($organization, 'crm', 'customers', 1),
        ]);
    }

    public function store(CustomerRequest $request)
    {
        $organization = $this->organization($request);

        if (! $this->subscriptions->withinLimit($organization, 'crm', 'customers', 1)) {
            throw ValidationException::withMessages([
                'name_ar' => __('crm.messages.customer_limit_reached'),
            ]);
        }

        $data = $request->validated();
        $data['organization_id'] = $organization->id;

        Customer::create($data);

        return redirect()
            ->route('company.crm.customers.index')
            ->with('success', __('crm.messages.customer_created'));
    }

    public function show(Request $request, Customer $customer)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $customer);

        $customer->load([
            'assignedTo:id,name',
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name'),
            'customerNotes' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at'),
            'interactions' => fn ($q) => $q->with('user:id,name')->orderByDesc('occurred_at'),
            'opportunities' => fn ($q) => $q->with(['stage:id,name_ar,name_en', 'assignedTo:id,name'])->orderByDesc('created_at'),
            'tasks' => fn ($q) => $q->with('assignedTo:id,name')->orderByDesc('created_at'),
        ]);

        return view('company.crm.customer-show', [
            'organization' => $organization,
            'customer' => $customer,
            'users' => $this->assignableUsers($organization),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $customer);

        $customer->update($request->validated());

        return redirect()
            ->route('company.crm.customers.index')
            ->with('success', __('crm.messages.customer_updated'));
    }

    public function destroy(Request $request, Customer $customer)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $customer);

        $customer->delete();

        return redirect()
            ->route('company.crm.customers.index')
            ->with('success', __('crm.messages.customer_deleted'));
    }

    public function storeNote(CustomerNoteRequest $request, Customer $customer)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $customer);

        CustomerNote::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'content' => $request->validated('content'),
        ]);

        return redirect()
            ->route('company.crm.customers.show', $customer)
            ->with('success', __('crm.messages.note_added'));
    }

    public function storeInteraction(InteractionRequest $request, Customer $customer)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $customer);

        $data = $request->validated();

        Interaction::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'content' => $data['content'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        return redirect()
            ->route('company.crm.customers.show', $customer)
            ->with('success', __('crm.messages.interaction_added'));
    }

    /**
     * Active users of the current organization, for assignment selects.
     *
     * @return Collection<int, User>
     */
    private function assignableUsers(Organization $organization): Collection
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
