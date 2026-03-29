<?php

declare(strict_types=1);

namespace App\Application\Parsing\Handler;

use App\Application\Parsing\Command\NotifyWebhookCommand;
use App\Application\Parsing\Command\ParseResumeCommand;
use App\Domain\Parsing\Exception\AiProviderException;
use App\Domain\Parsing\Exception\InvalidAiOutputException;
use App\Domain\Parsing\Exception\ScannedPdfException;
use App\Domain\Parsing\Model\ParseResult;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use App\Domain\Parsing\Service\AiProviderInterface;
use App\Domain\Parsing\Service\PdfExtractorInterface;
use App\Domain\Parsing\Service\SchemaValidatorInterface;
use App\Domain\Parsing\Service\TextCleanerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DDD note: this handler lives in the Application layer — it orchestrates
 * domain services and repositories but contains no business logic itself.
 * Each step is delegated to a domain service (PdfExtractor, TextCleaner,
 * SchemaValidator) or an infrastructure adapter (AiProvider). The handler
 * only sequences the calls and manages state transitions on ParseJob.
 */
#[AsMessageHandler]
final readonly class ParseResumeHandler
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private ParseResultRepositoryInterface $parseResultRepository,
        private PdfExtractorInterface $pdfExtractor,
        private TextCleanerInterface $textCleaner,
        private AiProviderInterface $aiProvider,
        private SchemaValidatorInterface $schemaValidator,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.parsing')] private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ParseResumeCommand $command): void
    {
        $job = $this->parseJobRepository->findById($command->jobId);

        if (null === $job || $job->isDone()) {
            return; // idempotency guard
        }

        $startNs = hrtime(true);

        $job->markAsProcessing();
        $this->parseJobRepository->save($job);

        $this->logger->info('parse_job.processing_started', ['job_id' => $command->jobId]);

        try {
            $text = $this->pdfExtractor->extract($command->filePath);
            $cleaned = $this->textCleaner->clean($text);

            $extraction = $this->aiProvider->extract($cleaned);
            $payload = $this->schemaValidator->validate($extraction->payload);

            $this->logger->info('parse_job.ai_extraction_done', [
                'job_id' => $command->jobId,
                'ai_provider' => $extraction->provider,
                'ai_model' => $extraction->aiModel,
                'ai_duration_ms' => $extraction->aiDurationMs,
                'tokens_total' => $extraction->tokensTotal,
                'was_truncated' => $cleaned->wasTruncated,
            ]);

            $result = ParseResult::create(Uuid::v7()->toRfc4122(), $job->getId(), $payload, $extraction);

            $this->parseResultRepository->save($result);

            $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);
            $job->markAsDone();
            $this->parseJobRepository->save($job);

            $this->logger->info('parse_job.completed', [
                'job_id' => $command->jobId,
                'status' => 'done',
                'duration_ms' => $durationMs,
            ]);
        } catch (ScannedPdfException $e) {
            $job->markAsFailed($e->getMessage(), 'SCANNED_PDF');
            $this->parseJobRepository->save($job);
            $this->logger->warning('parse_job.failed', [
                'job_id' => $command->jobId,
                'status' => 'failed',
                'error_code' => 'SCANNED_PDF',
                'duration_ms' => (int) round((hrtime(true) - $startNs) / 1_000_000),
            ]);
        } catch (AiProviderException|InvalidAiOutputException $e) {
            $job->markAsFailed($e->getMessage(), 'PROCESSING_ERROR');
            $this->parseJobRepository->save($job);
            $this->logger->error('parse_job.failed', [
                'job_id' => $command->jobId,
                'status' => 'failed',
                'error_code' => 'PROCESSING_ERROR',
                'duration_ms' => (int) round((hrtime(true) - $startNs) / 1_000_000),
            ]);
        } catch (\Throwable $e) {
            $job->markAsFailed($e->getMessage(), 'PROCESSING_ERROR');
            $this->parseJobRepository->save($job);
            $this->logger->error('parse_job.failed', [
                'job_id' => $command->jobId,
                'status' => 'failed',
                'error_code' => 'PROCESSING_ERROR',
                'duration_ms' => (int) round((hrtime(true) - $startNs) / 1_000_000),
            ]);
        }

        if ($job->hasWebhook()) {
            $this->messageBus->dispatch(new NotifyWebhookCommand($job->getId()));
        }
    }
}
