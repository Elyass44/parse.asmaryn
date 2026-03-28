<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use App\Application\Parsing\Command\ParseResumeCommand;
use App\Domain\Parsing\Model\ParseJob;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\ValueObject\OriginalFilename;
use App\Domain\Parsing\ValueObject\WebhookUrl;
use App\UI\Api\DTO\ParseUploadRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ParseUploadController extends AbstractApiController
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private MessageBusInterface $messageBus,
        private ValidatorInterface $validator,
        private RateLimiterFactoryInterface $parseUploadLimiter,
        #[Autowire(param: 'app.upload_dir')] private string $uploadDir,
    ) {
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
        $limiter = $this->parseUploadLimiter->create($request->getClientIp());
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            return $this->errorResponse(
                'RATE_LIMITED',
                'You have reached the daily parse limit. Please try again tomorrow.',
                [],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(0, $retryAfter)],
            );
        }

        $uploadedFile = $request->files->get('file');
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
                (string) $violations[0]->getMessage(),
                $details,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Validator already enforces HTTPS — safe to construct directly.
        $webhookUrl = null !== $webhookUrlStr ? new WebhookUrl($webhookUrlStr) : null;

        // Capture original filename before moving the file.
        $originalFilename = new OriginalFilename($uploadedFile->getClientOriginalName());

        $jobId = Uuid::v7()->toRfc4122();
        $filePath = sprintf('%s/%s.pdf', rtrim($this->uploadDir, '/'), $jobId);

        // Persist first — if the DB is down we don't want an orphaned file on disk.
        $job = ParseJob::create($jobId, $originalFilename, $webhookUrl);
        $this->parseJobRepository->save($job);

        $uploadedFile->move($this->uploadDir, $jobId.'.pdf');

        $this->messageBus->dispatch(new ParseResumeCommand($jobId, $filePath));

        return new JsonResponse([
            'job_id' => $jobId,
            'status' => $job->getStatus()->value,
            'poll_url' => '/api/parse/'.$jobId,
        ], Response::HTTP_ACCEPTED);
    }
}
