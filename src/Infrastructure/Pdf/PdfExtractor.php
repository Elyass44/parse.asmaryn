<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Parsing\Exception\ScannedPdfException;
use App\Domain\Parsing\Service\PdfExtractorInterface;
use Smalot\PdfParser\Parser;

/**
 * DDD note: concrete implementation of PdfExtractorInterface lives here in
 * Infrastructure because it depends on the smalot/pdfparser third-party library.
 * The Domain layer only knows about the interface — swapping the library later
 * requires changing only this file.
 */
final class PdfExtractor implements PdfExtractorInterface
{
    private const int SCANNED_PDF_THRESHOLD = 200;

    public function __construct(private readonly Parser $parser)
    {
    }

    public function extract(string $filePath): string
    {
        $pdf = $this->parser->parseFile($filePath);
        $raw = $pdf->getText();

        $normalised = $this->normalise($raw);

        if (mb_strlen($normalised) < self::SCANNED_PDF_THRESHOLD) {
            throw ScannedPdfException::fromCharCount(mb_strlen($normalised));
        }

        return $normalised;
    }

    /**
     * Strips excessive whitespace and normalises line breaks.
     * Keeps meaningful paragraph breaks while collapsing runs of spaces/tabs.
     */
    private function normalise(string $text): string
    {
        // Normalise Windows / Mac line endings to Unix
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Collapse horizontal whitespace (spaces/tabs) runs into a single space
        $text = preg_replace('/[^\S\n]+/', ' ', $text) ?? $text;

        // Collapse more than two consecutive newlines into exactly two
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
