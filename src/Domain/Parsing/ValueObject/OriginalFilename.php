<?php

declare(strict_types=1);

namespace App\Domain\Parsing\ValueObject;

final class OriginalFilename
{
    private const int MAX_LENGTH = 255;

    private readonly string $value;

    public function __construct(string $value)
    {
        // Remove null bytes
        $value = str_replace("\0", '', $value);

        // Strip path traversal — keep only the filename part
        $value = basename($value);

        // Truncate to limit
        if (mb_strlen($value) > self::MAX_LENGTH) {
            $extension = pathinfo($value, PATHINFO_EXTENSION);
            $name = pathinfo($value, PATHINFO_FILENAME);
            $maxName = self::MAX_LENGTH - ('' !== $extension ? mb_strlen($extension) + 1 : 0);
            $value = mb_substr($name, 0, $maxName).('' !== $extension ? '.'.$extension : '');
        }

        $this->value = $value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
