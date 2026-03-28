<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Exception;

final class AiProviderException extends \RuntimeException
{
    public static function fromResponse(int $statusCode, string $body): self
    {
        return new self(sprintf('Mistral API returned HTTP %d: %s', $statusCode, $body));
    }

    public static function fromTimeout(): self
    {
        return new self('Mistral API timed out.');
    }
}
