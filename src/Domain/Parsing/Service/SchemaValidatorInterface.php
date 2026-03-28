<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\Exception\InvalidAiOutputException;

/**
 * DDD note: validation of the AI output shape is domain logic — it enforces
 * the contract that the rest of the domain depends on. Keeping it behind an
 * interface lets us swap or extend validation rules without touching the handler.
 */
interface SchemaValidatorInterface
{
    /**
     * Validates and coerces the raw AI output into a well-shaped payload.
     *
     * Missing optional keys are filled with null; structural violations throw.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed> normalised payload safe to persist
     *
     * @throws InvalidAiOutputException if the output is structurally broken
     */
    public function validate(array $raw): array;
}
