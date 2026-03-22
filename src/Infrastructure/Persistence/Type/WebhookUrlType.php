<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use App\Domain\Parsing\ValueObject\WebhookUrl;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class WebhookUrlType extends StringType
{
    public const string NAME = 'webhook_url';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?WebhookUrl
    {
        if ($value === null) {
            return null;
        }

        return new WebhookUrl((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof WebhookUrl ? $value->toString() : (string) $value;
    }
}
