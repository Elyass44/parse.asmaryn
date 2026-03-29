<?php

declare(strict_types=1);

namespace App\Application\Parsing\Handler;

use App\Application\Parsing\Command\NotifyWebhookCommand;
use App\Application\Parsing\WebhookSenderInterface;
use App\Domain\Parsing\Model\JobStatus;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * DDD note: Application handler — orchestrates loading job data, building the
 * webhook payload, and delegating the HTTP call to the WebhookSenderInterface
 * port. No HTTP or business logic here.
 */
#[AsMessageHandler]
final readonly class NotifyWebhookHandler
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private ParseResultRepositoryInterface $parseResultRepository,
        private WebhookSenderInterface $webhookSender,
        #[Autowire(service: 'monolog.logger.parsing')] private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyWebhookCommand $command): void
    {
        $job = $this->parseJobRepository->findById($command->jobId);

        if (null === $job || null === $job->getWebhookUrl()) {
            return; // safety guard — nothing to notify
        }

        $payload = $this->buildPayload($command->jobId, $job->getStatus(), $job->getErrorCode(), $job->getErrorMessage());

        // Throws \RuntimeException on non-2xx/timeout — Messenger will retry.
        $this->webhookSender->send($job->getWebhookUrl()->toString(), $payload);

        $job->markWebhookDelivered();
        $this->parseJobRepository->save($job);

        $this->logger->info('parse_job.webhook_delivered', ['job_id' => $command->jobId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $jobId,
        JobStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
    ): array {
        if (JobStatus::Done === $status) {
            $result = $this->parseResultRepository->findByJobId($jobId);

            return [
                'job_id' => $jobId,
                'status' => $status->value,
                'result' => $result?->getPayload() ?? [],
            ];
        }

        return [
            'job_id' => $jobId,
            'status' => $status->value,
            'error' => [
                'code' => $errorCode ?? 'PROCESSING_ERROR',
                'message' => $errorMessage ?? 'An unexpected error occurred.',
            ],
        ];
    }
}
