<?php

declare(strict_types=1);

namespace App\Domain\Parsing\ValueObject;

/**
 * DDD note: immutable value object returned by TextCleaner.
 * Bundles the cleaned string with its truncation flag so callers
 * never have to infer state from string length.
 */
final readonly class CleanedText
{
    public function __construct(
        public string $text,
        public bool $wasTruncated,
    ) {
    }
}
