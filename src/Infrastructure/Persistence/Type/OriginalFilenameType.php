<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use App\Domain\Parsing\ValueObject\OriginalFilename;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class OriginalFilenameType extends StringType
{
    public const string NAME = 'original_filename';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?OriginalFilename
    {
        if (null === $value) {
            return null;
        }

        return new OriginalFilename((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof OriginalFilename ? $value->toString() : (string) $value;
    }
}
