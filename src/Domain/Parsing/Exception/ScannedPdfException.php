<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Exception;

/**
 * Thrown when a PDF contains no extractable text (scanned/image-based PDF).
 * OCR is out of scope for MVP — callers should surface this to the user.
 */
final class ScannedPdfException extends \DomainException
{
    public static function fromCharCount(int $charCount): self
    {
        return new self(
            sprintf(
                'No text could be extracted from this PDF (got %d characters, minimum is 200).',
                $charCount,
            )
        );
    }

    public static function fromEmptyDocument(): self
    {
        return new self('The PDF contains no pages and cannot be processed.');
    }
}
