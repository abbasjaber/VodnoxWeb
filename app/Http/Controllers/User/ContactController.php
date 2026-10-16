<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\FavoriteRequest;
use App\Models\ContactFavorite;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Organization directory (read-only) plus the agent's own favorites.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $term = trim((string) $request->query('q', ''));

        $directory = User::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%")
                        ->orWhere('extension', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'extension', 'phone', 'position']);

        $favorites = ContactFavorite::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return view('user.contacts', compact('organization', 'directory', 'favorites', 'term'));
    }

    public function store(FavoriteRequest $request)
    {
        $organization = $this->organization($request);
        $user = $request->user();
        $data = $request->validated();

        if (! empty($data['contact_id'])) {
            $contact = User::query()
                ->where('id', $data['contact_id'])
                ->where('organization_id', $organization->id)
                ->where('active', true)
                ->first();

            if (! $contact) {
                throw ValidationException::withMessages([
                    'contact_id' => __('portal.agent.contact_in_org'),
                ]);
            }

            $attributes = [
                'contact_id' => $contact->id,
                'name' => $contact->name,
                'phone_number' => $contact->extension ?: $contact->phone,
            ];
        } else {
            $attributes = [
                'contact_id' => null,
                'name' => $data['name'],
                'phone_number' => $data['phone_number'] ?? null,
            ];
        }

        $favorite = ContactFavorite::updateOrCreate(
            [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'contact_id' => $attributes['contact_id'],
                'name' => $attributes['name'],
            ],
            $attributes
        );

        $this->audit->log('agent.favorite_added', $favorite, [], [
            'name' => $favorite->name,
            'phone_number' => $favorite->phone_number,
        ]);

        return redirect()
            ->route('user.contacts.index')
            ->with('success', __('portal.agent.favorite_added'));
    }

    public function destroy(Request $request, ContactFavorite $favorite)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $favorite);

        // An agent can only remove their own favorites.
        abort_unless((int) $favorite->user_id === (int) $request->user()->id, 404);

        $this->audit->log('agent.favorite_removed', $favorite, [
            'name' => $favorite->name,
        ]);

        $favorite->delete();

        return redirect()
            ->route('user.contacts.index')
            ->with('success', __('portal.agent.favorite_removed'));
    }
}
