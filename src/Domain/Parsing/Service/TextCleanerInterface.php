<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\ValueObject\CleanedText;

/**
 * DDD note: interface lives in the Domain layer so the Application layer
 * depends on an abstraction. The concrete TextCleaner also lives in Domain
 * because it has no external dependencies — no infrastructure adapter needed.
 */
interface TextCleanerInterface
{
    public function clean(string $text): CleanedText;
}
