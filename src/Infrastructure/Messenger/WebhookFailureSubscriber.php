<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Parsing\Command\NotifyWebhookCommand;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * DDD note: Infrastructure subscriber that bridges Messenger's failure event
 * back to the domain. When all webhook delivery retries are exhausted and the
 * message lands in the dead-letter queue, this marks ParseJob.webhook_status
 * = failed — keeping the polling endpoint accurate without polling logic in
 * the handler itself.
 */
final class WebhookFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ParseJobRepositoryInterface $parseJobRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return; // more attempts remain — do not mark as failed yet
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof NotifyWebhookCommand) {
            return;
        }

        $job = $this->parseJobRepository->findById($message->jobId);

        if (null === $job) {
            return;
        }

        $job->markWebhookFailed();
        $this->parseJobRepository->save($job);
    }
}
