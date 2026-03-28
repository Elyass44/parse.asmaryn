<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

abstract readonly class AbstractApiController
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    protected function errorResponse(string $code, string $message, array $details, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status, $headers);
    }
}
