<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;

class ApiService
{
    /**
     * @param mixed $data
     * @param array|string $message
     * @param int $status
     * @return JsonResponse
     */
    public static function success($data, array|string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'messages' => is_string($message) ? [$message] : $message,
            'data' => $data,
        ], $status);
    }
    /**
     * @param int $status
     * @param array|string $message
     * @param mixed $data
     * @return JsonResponse
     */
    public static function error(int $status = 500, array|string $message = '', $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'messages' => is_string($message) ? [$message] : $message,
            'data' => $data,
        ], $status);
    }
}
