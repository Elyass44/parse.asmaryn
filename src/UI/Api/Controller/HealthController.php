<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Liveness probe for Traefik and uptime monitoring.
 *
 * UI layer only — checks infrastructure reachability, has no business logic.
 * Kept in a single controller action because there is no domain concept here.
 */
final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
        private HttpClientInterface $httpClient,
        private string $messengerTransportDsn,
    ) {
    }

    #[OA\Get(
        path: '/api/health',
        summary: 'Liveness check',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All systems operational',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'db', type: 'string', example: 'ok'),
                        new OA\Property(property: 'queue', type: 'string', example: 'ok'),
                    ],
                ),
            ),
            new OA\Response(
                response: 503,
                description: 'One or more dependencies are unavailable',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'error'),
                        new OA\Property(property: 'db', type: 'string', example: 'ok'),
                        new OA\Property(property: 'queue', type: 'string', example: 'error'),
                    ],
                ),
            ),
        ]
    )]
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $db = $this->checkDb();
        $queue = $this->checkQueue();

        $allOk = 'ok' === $db && 'ok' === $queue;

        return new JsonResponse(
            ['status' => $allOk ? 'ok' : 'error', 'db' => $db, 'queue' => $queue],
            $allOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function checkDb(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkQueue(): string
    {
        $parsed = parse_url($this->messengerTransportDsn);

        if (false === $parsed || !isset($parsed['host'])) {
            return 'error';
        }

        $host = $parsed['host'];
        $user = $parsed['user'] ?? 'guest';
        $pass = $parsed['pass'] ?? 'guest';
        $managementUrl = sprintf('http://%s:%s@%s:15672/api/healthchecks/node', $user, $pass, $host);

        try {
            $response = $this->httpClient->request('GET', $managementUrl, ['timeout' => 3]);
            $data = $response->toArray();

            return isset($data['status']) && 'ok' === $data['status'] ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
