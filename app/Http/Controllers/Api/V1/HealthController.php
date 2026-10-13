<?php

namespace App\Http\Controllers\Api\V1;

/**
 * Simple health-check endpoint for load balancers and monitoring.
 *
 * Returns only a minimal status without exposing versions, stack traces,
 * database credentials, or internal service details.
 */
class HealthController extends BaseApiController
{
    public function __invoke()
    {
        return $this->successResponse(
            message: __('api.health_ok'),
            data: ['status' => 'ok'],
            status: 200,
        );
    }
}
