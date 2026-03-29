<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use App\Domain\Parsing\Model\JobStatus;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ParseStatusController extends AbstractApiController
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private ParseResultRepositoryInterface $parseResultRepository,
    ) {
    }

    #[OA\Get(
        path: '/api/parse/{id}',
        summary: 'Poll the status of a parse job',
        tags: ['Parsing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job found — status pending, processing, done, or failed'),
            new OA\Response(response: 404, description: 'Job not found'),
        ]
    )]
    #[Route('/api/parse/{id}', name: 'api_parse_status', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $job = $this->parseJobRepository->findById($id);

        if (null === $job) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Parse job not found.',
                [],
                Response::HTTP_NOT_FOUND,
            );
        }

        $data = [
            'job_id' => $job->getId(),
            'status' => $job->getStatus()->value,
        ];

        if (JobStatus::Done === $job->getStatus()) {
            $result = $this->parseResultRepository->findByJobId($id);
            if (null !== $result?->getPayloadDeletedAt()) {
                $data['result_expired'] = true;
            } else {
                $data['result'] = $result?->getPayload() ?? [];
            }
        }

        if (JobStatus::Failed === $job->getStatus()) {
            $data['error'] = [
                'code' => $job->getErrorCode() ?? 'PROCESSING_ERROR',
                'message' => $job->getErrorMessage() ?? 'An unexpected error occurred.',
            ];
        }

        if ($job->hasWebhook()) {
            $data['webhook_status'] = $job->getWebhookStatus()?->value;
        }

        return new JsonResponse($data);
    }
}
