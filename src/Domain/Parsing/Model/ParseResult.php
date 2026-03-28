<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Model;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'parse_result')]
#[ORM\Index(name: 'idx_parse_result_job_id', columns: ['job_id'])]
class ParseResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $jobId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    private function __construct(
        string $id,
        string $jobId,
        array $payload,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->jobId = $jobId;
        $this->payload = $payload;
        $this->createdAt = $createdAt;
    }

    /** @param array<string, mixed> $payload */
    public static function create(string $id, string $jobId, array $payload): self
    {
        return new self(
            id: $id,
            jobId: $jobId,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
