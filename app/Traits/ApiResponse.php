<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param  mixed  $data
     */
    protected function success(string $message, $data = [], int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param  mixed  $errors
     */
    protected function error(string $message, $errors = [], int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
