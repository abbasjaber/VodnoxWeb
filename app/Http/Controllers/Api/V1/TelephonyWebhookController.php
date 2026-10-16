<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Services\Telephony\TelephonyWebhookService;
use Illuminate\Http\Request;

/**
 * Inbound telephony webhook (provider-agnostic).
 *
 * The endpoint is public but authenticated by an HMAC-SHA256 signature and is
 * idempotent by `event_id`. It is intentionally side-effect free beyond
 * recording the event: no call or usage rows are created here.
 */
class TelephonyWebhookController extends BaseApiController
{
    public const SIGNATURE_HEADER = 'X-VODNOX-Signature';

    public function __construct(private readonly TelephonyWebhookService $webhooks) {}

    public function __invoke(Request $request)
    {
        if (! $this->webhooks->isConfigured()) {
            return $this->errorResponse(__('api.webhook_not_configured'), null, 503);
        }

        $raw = $request->getContent();
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! $this->webhooks->verifySignature($raw, $signature)) {
            return $this->errorResponse(__('api.webhook_invalid_signature'), null, 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || empty($payload['event_id'])) {
            return $this->errorResponse(__('api.webhook_invalid_payload'), [
                'event_id' => [__('api.webhook_invalid_payload')],
            ], 422);
        }

        $payload['organization_id'] = $this->resolveOrganizationId($payload['organization_id'] ?? null);

        $result = $this->webhooks->record($payload, is_string($signature) ? $signature : null);

        return $this->successResponse(
            message: __($result['duplicate'] ? 'api.webhook_duplicate' : 'api.webhook_received'),
            data: [
                'event_id' => $result['event']->event_id,
                'status' => $result['event']->status,
                'duplicate' => $result['duplicate'],
            ],
        );
    }

    /**
     * An unknown organization id must not break the insert (foreign key);
     * fall back to null instead of trusting the payload.
     */
    private function resolveOrganizationId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return Organization::query()->whereKey($id)->exists() ? $id : null;
    }
}
