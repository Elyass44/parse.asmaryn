<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Model;

enum WebhookStatus: string
{
    case Pending   = 'pending';
    case Delivered = 'delivered';
    case Failed    = 'failed';
}
