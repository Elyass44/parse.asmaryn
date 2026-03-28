<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\Exception\AiProviderException;
use App\Domain\Parsing\ValueObject\CleanedText;
use App\Domain\Parsing\ValueObject\ExtractionResult;

/**
 * DDD note: this interface lives in the Domain layer so the Application layer
 * depends on an abstraction, not on Mistral or any specific AI provider.
 * Each provider (Mistral, OpenAI, Gemini…) is a separate Infrastructure/Ai
 * adapter. Swapping providers requires zero changes outside Infrastructure.
 */
interface AiProviderInterface
{
    /**
     * Extracts structured résumé data from cleaned text.
     *
     * Returns an ExtractionResult carrying both the decoded JSON payload and
     * the token usage reported by the provider (prompt, completion, total).
     *
     * @throws AiProviderException on HTTP error, timeout, or malformed response
     */
    public function extract(CleanedText $text): ExtractionResult;
}
