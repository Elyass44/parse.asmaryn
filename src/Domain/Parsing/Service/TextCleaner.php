<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\ValueObject\CleanedText;

/**
 * DDD note: pure domain service — no framework or library dependencies.
 * Lives in Domain alongside its interface because no infrastructure adapter
 * is needed; the implementation detail (regex) is not an external system.
 */
final class TextCleaner implements TextCleanerInterface
{
    private const int MAX_CHARS = 3000;
    private const string TRUNCATION_NOTE = "\n\n[...résumé truncated to 3000 characters]";

    public function clean(string $text): CleanedText
    {
        $text = $this->removePageArtefacts($text);
        $text = $this->collapseBlankLines($text);
        $text = trim($text);

        if (mb_strlen($text) <= self::MAX_CHARS) {
            return new CleanedText($text, false);
        }

        $truncated = mb_substr($text, 0, self::MAX_CHARS).self::TRUNCATION_NOTE;

        return new CleanedText($truncated, true);
    }

    /**
     * Removes common PDF artefacts: isolated page numbers and "Page N [of M]" lines.
     */
    private function removePageArtefacts(string $text): string
    {
        // Standalone page numbers on their own line (e.g. "3", "  12  ")
        $text = preg_replace('/^\s*\d{1,4}\s*$/m', '', $text) ?? $text;

        // "Page 3" / "Page 3 of 10" / "page 3/10" variants (case-insensitive)
        $text = preg_replace('/^\s*page\s+\d+(\s+(of\s+\d+|\d+))?\s*$/im', '', $text) ?? $text;

        return $text;
    }

    /**
     * Collapses runs of more than two consecutive blank lines into exactly two.
     */
    private function collapseBlankLines(string $text): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    }
}
