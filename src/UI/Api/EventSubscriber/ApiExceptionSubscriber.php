<?php

declare(strict_types=1);

namespace App\UI\Api\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Catches all exceptions on /api/* routes and returns the standard error shape.
 *
 * Lives in UI/Api because it is purely an HTTP presentation concern — it
 * translates exceptions into the API error envelope. Domain layers never
 * know this subscriber exists.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        [$code, $message, $status] = $this->resolveError($exception);

        $event->setResponse(new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message, 'details' => new \stdClass()]],
            $status,
        ));
    }

    /** @return array{string, string, int} */
    private function resolveError(\Throwable $e): array
    {
        if ($e instanceof HttpExceptionInterface) {
            return match ($e->getStatusCode()) {
                Response::HTTP_NOT_FOUND => ['NOT_FOUND', 'The requested resource was not found.', Response::HTTP_NOT_FOUND],
                Response::HTTP_METHOD_NOT_ALLOWED => ['VALIDATION_ERROR', 'Method not allowed.', Response::HTTP_METHOD_NOT_ALLOWED],
                Response::HTTP_TOO_MANY_REQUESTS => ['RATE_LIMITED', 'Too many requests.', Response::HTTP_TOO_MANY_REQUESTS],
                default => ['PROCESSING_ERROR', $e->getMessage() ?: 'An unexpected error occurred.', $e->getStatusCode()],
            };
        }

        return ['PROCESSING_ERROR', 'An unexpected error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR];
    }
}
