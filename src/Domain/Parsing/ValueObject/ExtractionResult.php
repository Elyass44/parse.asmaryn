<?php

declare(strict_types=1);

namespace App\Domain\Parsing\ValueObject;

/**
 * DDD note: Value Object returned by AiProviderInterface::extract().
 * It bundles the extracted payload with the token usage data reported by the
 * provider. Keeping it here in the Domain means the Application layer and the
 * domain interface stay decoupled from any provider-specific response shape.
 */
final readonly class ExtractionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public int $tokensPrompt,
        public int $tokensCompletion,
        public int $tokensTotal,
        public string $provider,
        public string $aiModel,
        public int $aiDurationMs,
    ) {
    }
}
