<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use OpenApi\Attributes as OA;
use Symfony\Component\Uid\Uuid;
use App\Domain\Parsing\Model\ParseJob;
use App\UI\Api\DTO\ParseUploadRequest;
use Symfony\Component\HttpFoundation\Request;
use App\Domain\Parsing\ValueObject\WebhookUrl;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Domain\Parsing\ValueObject\OriginalFilename;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Application\Parsing\Command\ParseResumeCommand;
use App\Domain\Parsing\Exception\InvalidWebhookUrlException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;

final readonly class ParseUploadController
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private MessageBusInterface         $messageBus,
        private ValidatorInterface          $validator,
        private string                      $uploadDir,
    )
    {
    }

    #[OA\Post(
        path: '/api/parse',
        description: 'Accepts a multipart PDF upload, stores the file, and dispatches an async parsing job. Poll the returned `poll_url` to retrieve the result.',
        summary: 'Upload a PDF résumé for parsing',
        tags: ['Parsing'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [
                    new OA\Property(property: 'file', description: 'PDF file, max 5MB', type: 'string', format: 'binary'),
                    new OA\Property(property: 'webhook_url', description: 'Optional HTTPS URL to POST the result to when processing completes', type: 'string', format: 'uri', example: 'https://ats.example.com/webhooks/resume'),
                ],
            ),
        ),
    )]
    #[OA\Response(
        response: 202,
        description: 'Job accepted — poll the returned URL for status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'job_id', type: 'string', format: 'uuid', example: '018f1a2b-3c4d-7e5f-8a9b-0c1d2e3f4a5b'),
                new OA\Property(property: 'status', type: 'string', enum: ['pending'], example: 'pending'),
                new OA\Property(property: 'poll_url', type: 'string', example: '/api/parse/018f1a2b-3c4d-7e5f-8a9b-0c1d2e3f4a5b'),
            ],
        ),
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error (invalid file type, size exceeded, bad webhook URL)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'error',
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_ERROR'),
                        new OA\Property(property: 'message', type: 'string', example: 'The uploaded file is not a valid PDF.'),
                        new OA\Property(property: 'details', type: 'object'),
                    ],
                    type: 'object',
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 429,
        description: 'Rate limit exceeded (5 parses per IP per day)',
    )]
    #[Route('/api/parse', name: 'api_parse_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $uploadedFile  = $request->files->get('file');
        $webhookUrlStr = $request->request->get('webhook_url') ?: null;

        $dto = new ParseUploadRequest(
            file: $uploadedFile,
            webhookUrl: $webhookUrlStr,
        );

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $details = [];
            foreach ($violations as $violation) {
                $details[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return $this->errorResponse(
                'VALIDATION_ERROR',
                (string)$violations[0]->getMessage(),
                $details,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $webhookUrl = null;
        if ($webhookUrlStr !== null) {
            try {
                $webhookUrl = new WebhookUrl($webhookUrlStr);
            } catch (InvalidWebhookUrlException $e) {
                return $this->errorResponse(
                    'VALIDATION_ERROR',
                    $e->getMessage(),
                    ['webhook_url' => $e->getMessage()],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        // Capture original filename before moving the file.
        $originalFilename = new OriginalFilename($uploadedFile->getClientOriginalName());

        $jobId = Uuid::v7()->toRfc4122();

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        $uploadedFile->move($this->uploadDir, $jobId . '.pdf');

        $job = ParseJob::create($jobId, $originalFilename, $webhookUrl);
        $this->parseJobRepository->save($job);

        $this->messageBus->dispatch(
            new ParseResumeCommand($jobId, $this->uploadDir . '/' . $jobId . '.pdf'),
        );

        return new JsonResponse([
            'job_id'   => $jobId,
            'status'   => $job->getStatus()->value,
            'poll_url' => '/api/parse/' . $jobId,
        ], Response::HTTP_ACCEPTED);
    }

    private function errorResponse(string $code, string $message, array $details, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
