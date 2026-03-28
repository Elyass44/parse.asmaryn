<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Parsing\WebhookSenderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DDD note: infrastructure adapter that implements the Application-layer port.
 * All HTTP and signing concerns are isolated here — the handler never touches
 * HttpClient directly.
 */
#[AsAlias(WebhookSenderInterface::class)]
final class WebhookSender implements WebhookSenderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'WEBHOOK_SECRET')] private readonly string $webhookSecret,
    ) {
    }

    public function send(string $url, array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, $this->webhookSecret);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature' => $signature,
            ],
            'body' => $body,
            'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Webhook delivery failed: HTTP %d from %s', $statusCode, $url));
        }
    }
}
