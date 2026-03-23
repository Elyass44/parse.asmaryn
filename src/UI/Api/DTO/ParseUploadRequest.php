<?php

declare(strict_types=1);

namespace App\UI\Api\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO for POST /api/parse.
 */
final readonly class ParseUploadRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'A PDF file is required.')]
        #[Assert\File(
            maxSize: '5M',
            mimeTypes: ['application/pdf'],
            maxSizeMessage: 'The file exceeds the maximum allowed size of 5MB.',
            mimeTypesMessage: 'The uploaded file is not a valid PDF.',
        )]
        public ?UploadedFile $file,

        #[Assert\Url(
            message: 'The webhook URL must be a valid HTTPS URL.',
            protocols: ['https'],
        )]
        public ?string       $webhookUrl,
    )
    {
    }
}
