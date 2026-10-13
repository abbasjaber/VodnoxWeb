<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Unified JSON response contract for all VODNOX API endpoints.
 *
 * Every successful or error response uses the same envelope:
 * {
 *   "success": bool,
 *   "message": string,
 *   "data": mixed,
 *   "errors": object|null
 * }
 *
 * Pagination metadata is added automatically when a paginator is passed.
 */
trait ApiResponse
{
    /**
     * Return a successful JSON response.
     *
     * @param  mixed  $data
     */
    protected function successResponse(
        string $message,
        $data = null,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    /**
     * Return a paginated successful JSON response with metadata.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     */
    protected function paginatedResponse(
        string $message,
        $paginator,
        ?string $resourceClass = null,
        int $status = 200,
    ): JsonResponse {
        $items = $paginator->items();

        if ($resourceClass && is_subclass_of($resourceClass, JsonResource::class)) {
            $items = $resourceClass::collection($items);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $items,
            'errors' => null,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], $status);
    }

    /**
     * Return an error JSON response.
     *
     * @param  mixed  $errors
     */
    protected function errorResponse(
        string $message,
        $errors = null,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
