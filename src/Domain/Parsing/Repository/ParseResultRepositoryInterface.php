<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Repository;

use App\Domain\Parsing\Model\ParseResult;

interface ParseResultRepositoryInterface
{
    public function save(ParseResult $result): void;

    public function findByJobId(string $jobId): ?ParseResult;

    public function deleteByJobId(string $jobId): void;
}
