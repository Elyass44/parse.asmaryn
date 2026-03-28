<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Model;

enum JobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
