<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\PhoneNumberRequest;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Services\AuditService;
use Illuminate\Http\Request;

class NumberController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $numbers = PhoneNumber::query()
            ->forOrganization($org->id)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('number', 'like', "%{$q}%")->orWhere('label', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $extensions = $org->extensions()->with('user')->orderBy('extension')->get();
        $ivrMenus = IvrMenu::query()->where('organization_id', $org->id)->orderBy('id')->get();
        $queues = CallQueue::query()->where('organization_id', $org->id)->orderBy('id')->get();

        // Human-readable routing labels keyed by the stored routing value.
        $routingLabels = [];
        foreach ($extensions as $extension) {
            $routingLabels['extension:'.$extension->id] = __('company.dest_extension').' '.$extension->extension;
        }
        foreach ($ivrMenus as $menu) {
            $routingLabels['ivr:'.$menu->id] = $menu->name_ar;
        }
        foreach ($queues as $queue) {
            $routingLabels['queue:'.$queue->id] = $queue->name_ar;
        }
        $routingLabels['voicemail'] = __('company.dest_voicemail');

        // Group B: the gateway lines, with the whole routing rule set loaded so
        // the summary below reflects the same rows the dialplan is built from.
        $gatewayDevices = $org->gatewayDevices()
            ->with([
                'lines.route.defaultDestination',
                'lines.route.outsideHoursDestination',
                'lines.route.weekendDestination',
                'lines.route.dateExceptions',
                'lines.route.businessHours',
                'lines.route.weekendDays',
            ])
            ->orderBy('label')
            ->get();

        // Where each internal extension is already reached from, so an
        // administrator sees the real, existing linkage instead of creating a
        // second forwarding entry for the same employee.
        $extensionUsage = [];
        foreach ($gatewayDevices as $device) {
            foreach ($device->lines as $line) {
                $route = $line->route;
                $targets = array_filter([
                    $route?->default_destination_extension_id,
                    $route?->outside_hours_destination_extension_id,
                    $route?->weekend_destination_extension_id,
                    $route?->temporary_forward_extension_id,
                ]);
                foreach (array_unique($targets) as $extensionId) {
                    $extensionUsage[$extensionId][] = $device->label.' · #'.$line->line_index;
                }
            }
        }

        return view('company.numbers', compact(
            'org', 'numbers', 'extensions', 'ivrMenus', 'queues', 'routingLabels',
            'gatewayDevices', 'extensionUsage', 'q', 'status',
        ));
    }

    public function store(PhoneNumberRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $number = new PhoneNumber([
            'organization_id' => $org->id,
            'number' => $data['number'],
            'label' => $data['label'] ?? null,
            'status' => $data['status'],
        ]);

        $this->applyRouting($number, $data['routing']);
        $number->save();

        $this->audit->log('number.created', $number, [], $number->only(['number', 'label', 'status', 'routing_type', 'routing_target_id']), null, $org->id);
        $this->dispatchProvisioning($org, $number);

        return redirect()->route('company.numbers.index')->with('status', __('company.created'));
    }

    public function update(PhoneNumberRequest $request, PhoneNumber $number)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $number);

        $data = $request->validated();
        $old = $number->only(['number', 'label', 'status', 'routing_type', 'routing_target_id']);

        $number->fill([
            'number' => $data['number'],
            'label' => $data['label'] ?? null,
            'status' => $data['status'],
        ]);

        $this->applyRouting($number, $data['routing']);
        $number->save();

        $this->audit->log('number.updated', $number, $old, $number->only(['number', 'label', 'status', 'routing_type', 'routing_target_id']), null, $org->id);
        $this->dispatchProvisioning($org, $number);

        return redirect()->route('company.numbers.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, PhoneNumber $number)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $number);

        $this->audit->log('number.deleted', $number, ['number' => $number->number], [], null, $org->id);

        $number->delete();

        return redirect()->route('company.numbers.index')->with('status', __('company.deleted'));
    }

    /**
     * Map the validated "type:id" routing value onto the phone_numbers columns.
     */
    private function applyRouting(PhoneNumber $number, string $routing): void
    {
        [$type, $id] = array_pad(explode(':', $routing, 2), 2, null);
        $id = $id !== null ? (int) $id : null;

        $number->ivr_menu_id = $type === 'ivr' ? $id : null;
        $number->call_queue_id = $type === 'queue' ? $id : null;
        $number->routing_type = $type;
        $number->routing_target_id = in_array($type, ['voicemail'], true) ? null : $id;
    }

    private function dispatchProvisioning(Organization $org, PhoneNumber $number): void
    {
        $payload = [
            'organization_id' => $org->id,
            'number_id' => $number->id,
            'number' => $number->number,
            'routing_type' => $number->routing_type,
            'routing_target_id' => $number->routing_target_id,
            'status' => $number->status,
        ];

        $key = 'number:'.$number->id.':'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_organization', $org->id, $payload, $key);
    }
}
