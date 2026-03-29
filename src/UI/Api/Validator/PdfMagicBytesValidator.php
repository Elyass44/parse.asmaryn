<?php

declare(strict_types=1);

namespace App\UI\Api\Validator;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PdfMagicBytesValidator extends ConstraintValidator
{
    private const string PDF_MAGIC = '%PDF';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PdfMagicBytes) {
            throw new UnexpectedTypeException($constraint, PdfMagicBytes::class);
        }

        // Null / invalid upload is handled by Assert\NotNull and Assert\File — skip here.
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return;
        }

        $handle = @fopen($value->getPathname(), 'rb');
        if (false === $handle) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        if (self::PDF_MAGIC !== $magic) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
