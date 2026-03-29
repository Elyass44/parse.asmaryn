<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Model;

use App\Domain\Parsing\ValueObject\OriginalFilename;
use App\Domain\Parsing\ValueObject\WebhookUrl;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'parse_job')]
#[ORM\Index(name: 'idx_parse_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_parse_job_created_at', columns: ['created_at'])]
class ParseJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 20, enumType: JobStatus::class)]
    private JobStatus $status;

    #[ORM\Column(type: 'original_filename', length: 255)]
    private OriginalFilename $originalFilename;

    #[ORM\Column(type: 'webhook_url', length: 2048, nullable: true)]
    private ?WebhookUrl $webhookUrl;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: WebhookStatus::class)]
    private ?WebhookStatus $webhookStatus;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $errorCode;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        string $id,
        JobStatus $status,
        OriginalFilename $originalFilename,
        ?WebhookUrl $webhookUrl,
        ?WebhookStatus $webhookStatus,
        ?string $errorMessage,
        ?string $errorCode,
        ?\DateTimeImmutable $startedAt,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->originalFilename = $originalFilename;
        $this->webhookUrl = $webhookUrl;
        $this->webhookStatus = $webhookStatus;
        $this->errorMessage = $errorMessage;
        $this->errorCode = $errorCode;
        $this->startedAt = $startedAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public static function create(
        string $id,
        OriginalFilename $originalFilename,
        ?WebhookUrl $webhookUrl = null,
    ): self {
        $now = new \DateTimeImmutable();

        return new self(
            id: $id,
            status: JobStatus::Pending,
            originalFilename: $originalFilename,
            webhookUrl: $webhookUrl,
            webhookStatus: null !== $webhookUrl ? WebhookStatus::Pending : null,
            errorMessage: null,
            errorCode: null,
            startedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function markAsProcessing(): void
    {
        $this->status = JobStatus::Processing;
        $this->startedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsDone(): void
    {
        $this->status = JobStatus::Done;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsFailed(string $errorMessage, string $errorCode = 'PROCESSING_ERROR'): void
    {
        $this->status = JobStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->errorCode = $errorCode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markWebhookDelivered(): void
    {
        $this->webhookStatus = WebhookStatus::Delivered;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markWebhookFailed(): void
    {
        $this->webhookStatus = WebhookStatus::Failed;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function getOriginalFilename(): OriginalFilename
    {
        return $this->originalFilename;
    }

    public function getWebhookUrl(): ?WebhookUrl
    {
        return $this->webhookUrl;
    }

    public function getWebhookStatus(): ?WebhookStatus
    {
        return $this->webhookStatus;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDone(): bool
    {
        return JobStatus::Done === $this->status;
    }

    public function hasWebhook(): bool
    {
        return null !== $this->webhookUrl;
    }
}
