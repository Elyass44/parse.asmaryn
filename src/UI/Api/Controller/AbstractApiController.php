<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

abstract readonly class AbstractApiController
{
    /** @param array<string, mixed> $details */
    protected function errorResponse(string $code, string $message, array $details, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
