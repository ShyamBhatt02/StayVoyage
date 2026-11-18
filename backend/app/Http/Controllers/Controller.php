<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function ok(mixed $data = null, string $message = 'OK', array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, $message, 200, $meta);
    }

    protected function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return ApiResponse::created($data, $message);
    }

    protected function fail(string $message, int $status = 400, array $errors = [], ?string $code = null): JsonResponse
    {
        return ApiResponse::error($message, $status, $errors, $code);
    }
}
