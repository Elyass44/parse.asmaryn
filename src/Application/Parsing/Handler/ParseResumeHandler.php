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
    ) {
    }

    public function __invoke(ParseResumeCommand $command): void
    {
        $job = $this->parseJobRepository->findById($command->jobId);

        if (null === $job || $job->isDone()) {
            return; // idempotency guard
        }

        $job->markAsProcessing();
        $this->parseJobRepository->save($job);

        try {
            $duplicate = null !== $job->getContentHash()
                ? $this->parseJobRepository->findDoneByContentHash($job->getContentHash(), $job->getId())
                : null;

            if (null !== $duplicate) {
                $existing = $this->parseResultRepository->findByJobId($duplicate->getId());
            }

            if (null !== $duplicate && null !== $existing) {
                $result = ParseResult::create(Uuid::v7()->toRfc4122(), $job->getId(), $existing->getPayload(), $existing->toExtractionResult());
            } else {
                $text = $this->pdfExtractor->extract($command->filePath);
                $cleaned = $this->textCleaner->clean($text);

                $extraction = $this->aiProvider->extract($cleaned);
                $payload = $this->schemaValidator->validate($extraction->payload);

                $result = ParseResult::create(Uuid::v7()->toRfc4122(), $job->getId(), $payload, $extraction);
            }

            $this->parseResultRepository->save($result);

            $job->markAsDone();
            $this->parseJobRepository->save($job);
        } catch (ScannedPdfException $e) {
            $job->markAsFailed($e->getMessage(), 'SCANNED_PDF');
            $this->parseJobRepository->save($job);
        } catch (AiProviderException|InvalidAiOutputException $e) {
            $job->markAsFailed($e->getMessage(), 'PROCESSING_ERROR');
            $this->parseJobRepository->save($job);
        } catch (\Throwable $e) {
            $job->markAsFailed($e->getMessage(), 'PROCESSING_ERROR');
            $this->parseJobRepository->save($job);
        }

        if ($job->hasWebhook()) {
            $this->messageBus->dispatch(new NotifyWebhookCommand($job->getId()));
        }
    }
}
