<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\Exception\ScannedPdfException;

/**
 * DDD note: this interface lives in the Domain layer so the Application layer
 * can depend on an abstraction — not on smalot/pdfparser or any other library.
 * The concrete implementation (PdfExtractor) lives in Infrastructure/Pdf and
 * is injected by the DI container. This keeps the domain free of framework or
 * third-party dependencies.
 */
interface PdfExtractorInterface
{
    /**
     * Extracts raw text from a PDF file.
     *
     * @param string $filePath absolute path to the PDF file
     *
     * @throws ScannedPdfException if the extracted text is under 200 characters
     * @throws \RuntimeException   if the file cannot be read or parsed
     */
    public function extract(string $filePath): string;
}
