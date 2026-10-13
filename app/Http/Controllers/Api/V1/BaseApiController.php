<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiResponse;
use App\Http\Controllers\Controller;

/**
 * Base controller for all API V1 endpoints.
 *
 * Centralizes the unified JSON response envelope so every V1 controller
 * returns a consistent structure. Business modules will extend this class.
 */
abstract class BaseApiController extends Controller
{
    use ApiResponse;
}
