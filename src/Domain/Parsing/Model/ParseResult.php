<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Model;

use App\Domain\Parsing\ValueObject\ExtractionResult;
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

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tokensPrompt;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tokensCompletion;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tokensTotal;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $aiProvider;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $aiDurationMs;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    private function __construct(
        string $id,
        string $jobId,
        array $payload,
        ?int $tokensPrompt,
        ?int $tokensCompletion,
        ?int $tokensTotal,
        ?string $aiProvider,
        ?int $aiDurationMs,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->jobId = $jobId;
        $this->payload = $payload;
        $this->tokensPrompt = $tokensPrompt;
        $this->tokensCompletion = $tokensCompletion;
        $this->tokensTotal = $tokensTotal;
        $this->aiProvider = $aiProvider;
        $this->aiDurationMs = $aiDurationMs;
        $this->createdAt = $createdAt;
    }

    /** @param array<string, mixed> $payload */
    public static function create(string $id, string $jobId, array $payload, ExtractionResult $extraction): self
    {
        return new self(
            id: $id,
            jobId: $jobId,
            payload: $payload,
            tokensPrompt: $extraction->tokensPrompt,
            tokensCompletion: $extraction->tokensCompletion,
            tokensTotal: $extraction->tokensTotal,
            aiProvider: $extraction->provider,
            aiDurationMs: $extraction->aiDurationMs,
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

    public function getTokensPrompt(): ?int
    {
        return $this->tokensPrompt;
    }

    public function getTokensCompletion(): ?int
    {
        return $this->tokensCompletion;
    }

    public function getTokensTotal(): ?int
    {
        return $this->tokensTotal;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function getAiDurationMs(): ?int
    {
        return $this->aiDurationMs;
    }

    /**
     * Reconstructs an ExtractionResult from persisted fields so the handler
     * can copy a cached result without re-calling the AI provider.
     */
    public function toExtractionResult(): ExtractionResult
    {
        return new ExtractionResult(
            payload: $this->payload,
            tokensPrompt: 0,
            tokensCompletion: 0,
            tokensTotal: 0,
            provider: $this->aiProvider ?? '',
            aiDurationMs: 0,
        );
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
