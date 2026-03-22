<?php

declare(strict_types=1);

namespace App\Domain\Parsing\ValueObject;

use App\Domain\Parsing\Exception\InvalidWebhookUrlException;

final class WebhookUrl
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidWebhookUrlException(sprintf('"%s" is not a valid URL.', $value));
        }

        if (strtolower(parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidWebhookUrlException('Webhook URL must use HTTPS.');
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
