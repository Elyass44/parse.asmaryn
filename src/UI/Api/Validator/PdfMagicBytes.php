<?php

declare(strict_types=1);

namespace App\UI\Api\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that an uploaded file begins with the PDF magic bytes (%PDF).
 *
 * DDD note: this constraint lives in the UI layer because it is a boundary
 * validation concern — it ensures the raw HTTP input is structurally valid
 * before any domain logic runs. The domain never needs to know about magic bytes.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PdfMagicBytes extends Constraint
{
    public string $message = 'The uploaded file is not a valid PDF.';
}
