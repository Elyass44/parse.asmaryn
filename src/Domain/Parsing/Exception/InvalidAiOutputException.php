<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Exception;

final class InvalidAiOutputException extends \RuntimeException
{
    public static function fromMissingKey(string $key): self
    {
        return new self(sprintf('AI output is missing required key "%s".', $key));
    }

    public static function fromInvalidType(string $key, string $expected): self
    {
        return new self(sprintf('AI output key "%s" must be of type %s.', $key, $expected));
    }
}
