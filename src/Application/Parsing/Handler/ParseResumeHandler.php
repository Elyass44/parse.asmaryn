<?php

declare(strict_types=1);

namespace App\Application\Parsing\Handler;

use App\Application\Parsing\Command\NotifyWebhookCommand;
use App\Application\Parsing\Command\ParseResumeCommand;
use App\Domain\Parsing\Exception\ScannedPdfException;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\Service\PdfExtractorInterface;
use App\Domain\Parsing\Service\TextCleanerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * DDD note: this handler lives in the Application layer — it orchestrates
 * domain services and repositories but contains no business logic itself.
 * Each step is delegated to a domain service (PdfExtractor, TextCleaner, etc.)
 * or an infrastructure adapter (MistralProvider). The handler only sequences
 * the calls and manages state transitions on ParseJob.
 */
#[AsMessageHandler]
final readonly class ParseResumeHandler
{
    public function __construct(
        private ParseJobRepositoryInterface $parseJobRepository,
        private PdfExtractorInterface $pdfExtractor,
        private TextCleanerInterface $textCleaner,
        private MessageBusInterface $messageBus,
        // TODO MVP-030: AiProviderInterface $aiProvider,
        // TODO MVP-032: SchemaValidatorInterface $schemaValidator,
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
            $text = $this->pdfExtractor->extract($command->filePath);
            $cleaned = $this->textCleaner->clean($text);

            // TODO MVP-030: $raw     = $this->aiProvider->extract($cleaned->text);
            // TODO MVP-032: $payload = $this->schemaValidator->validate($raw);
            //               $result  = ParseResult::create(Uuid::v7()->toRfc4122(), $job->getId(), $payload);
            //               $this->parseResultRepository->save($result); // inject ParseResultRepositoryInterface in MVP-030
            //               $job->markAsDone();
            //               $this->parseJobRepository->save($job);
        } catch (ScannedPdfException $e) {
            $job->markAsFailed($e->getMessage(), 'SCANNED_PDF');
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
