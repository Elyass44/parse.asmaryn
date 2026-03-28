<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Repository;

use App\Domain\Parsing\Model\ParseJob;

interface ParseJobRepositoryInterface
{
    public function save(ParseJob $job): void;

    public function findById(string $id): ?ParseJob;
}
