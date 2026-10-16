<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\QueueRequest;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $queues = CallQueue::query()
            ->where('organization_id', $org->id)
            ->with('members')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name_ar', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $users = $org->users()->where('active', true)->orderBy('name')->get();

        return view('company.queues', compact('org', 'queues', 'users', 'q', 'status'));
    }

    public function store(QueueRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $queue = CallQueue::create([
            'organization_id' => $org->id,
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'strategy' => $data['strategy'],
            'timeout' => $data['timeout'],
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->syncMembers($queue, $data['members']);
        $this->audit->log('queue.created', $queue, [], $queue->only(['name_ar', 'name_en', 'strategy', 'timeout', 'is_active']), null, $org->id);
        $this->dispatchProvisioning($org, $queue);

        return redirect()->route('company.queues.index')->with('status', __('company.created'));
    }

    public function update(QueueRequest $request, CallQueue $queue)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $queue);

        $data = $request->validated();
        $old = $queue->only(['name_ar', 'name_en', 'strategy', 'timeout', 'is_active']);

        $queue->fill([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'strategy' => $data['strategy'],
            'timeout' => $data['timeout'],
            'is_active' => $request->boolean('is_active'),
        ])->save();

        $this->syncMembers($queue, $data['members']);
        $this->audit->log('queue.updated', $queue, $old, $queue->only(['name_ar', 'name_en', 'strategy', 'timeout', 'is_active']), null, $org->id);
        $this->dispatchProvisioning($org, $queue);

        return redirect()->route('company.queues.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, CallQueue $queue)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $queue);

        $this->audit->log('queue.deleted', $queue, ['name_ar' => $queue->name_ar, 'name_en' => $queue->name_en], [], null, $org->id);

        $queue->delete();

        return redirect()->route('company.queues.index')->with('status', __('company.deleted'));
    }

    /**
     * @param  array<int, mixed>  $userIds
     */
    private function syncMembers(CallQueue $queue, array $userIds): void
    {
        $map = [];
        foreach (array_values($userIds) as $index => $userId) {
            $map[(int) $userId] = ['order' => $index + 1];
        }

        $queue->members()->sync($map);
    }

    private function dispatchProvisioning(Organization $org, CallQueue $queue): void
    {
        $payload = [
            'organization_id' => $org->id,
            'queue_id' => $queue->id,
            'name_ar' => $queue->name_ar,
            'name_en' => $queue->name_en,
            'strategy' => $queue->strategy,
            'timeout' => $queue->timeout,
            'members' => $queue->members()->pluck('users.id')->all(),
        ];

        $key = 'queue:'.$queue->id.':'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_queue', $org->id, $payload, $key);
    }
}
