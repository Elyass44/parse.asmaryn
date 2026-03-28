<?php

declare(strict_types=1);

namespace App\Application\Parsing\Command;

final readonly class NotifyWebhookCommand
{
    public function __construct(
        public string $jobId,
    ) {
    }
}
