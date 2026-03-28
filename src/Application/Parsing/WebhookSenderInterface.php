<?php

declare(strict_types=1);

namespace App\Application\Parsing;

/**
 * DDD note: port defined by the Application layer use case.
 * The Application handler depends on this abstraction; the concrete
 * implementation (WebhookSender) lives in Infrastructure/Http and uses
 * Symfony HttpClient — keeping the Application layer free of HTTP concerns.
 */
interface WebhookSenderInterface
{
    /**
     * POSTs the payload as JSON to the given URL, signed with X-Signature.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException on non-2xx response or connection timeout
     */
    public function send(string $url, array $payload): void;
}
