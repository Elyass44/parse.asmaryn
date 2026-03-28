<?php

declare(strict_types=1);

namespace App\Domain\Parsing\Service;

use App\Domain\Parsing\Exception\InvalidAiOutputException;

/**
 * DDD note: this is a Domain service — it enforces the structural contract
 * that the rest of the domain relies on. It contains pure validation logic
 * with no framework or infrastructure dependencies.
 *
 * Coercion policy: missing optional scalar keys → null, missing array keys → [].
 * Structural violations (missing required keys, wrong array types) → exception.
 */
final class SchemaValidator implements SchemaValidatorInterface
{
    private const REQUIRED_KEYS = ['personal', 'experiences', 'education', 'skills', 'languages', 'certifications'];

    private const PERSONAL_REQUIRED = ['name', 'email', 'phone', 'location'];
    private const PERSONAL_OPTIONAL = ['linkedin', 'website'];

    /** @param array<string, mixed> $raw */
    public function validate(array $raw): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $raw)) {
                throw InvalidAiOutputException::fromMissingKey($key);
            }
        }

        return [
            'personal' => $this->validatePersonal($raw['personal']),
            'summary' => isset($raw['summary']) && is_string($raw['summary']) ? $raw['summary'] : null,
            'experiences' => $this->validateArrayOf($raw['experiences'], 'experiences', $this->coerceExperience(...)),
            'education' => $this->validateArrayOf($raw['education'], 'education', $this->coerceEducation(...)),
            'skills' => $this->validateStringArray($raw['skills'], 'skills'),
            'languages' => $this->validateArrayOf($raw['languages'], 'languages', $this->coerceLanguage(...)),
            'certifications' => $this->validateStringArray($raw['certifications'], 'certifications'),
        ];
    }

    /** @return array<string, string|null> */
    private function validatePersonal(mixed $value): array
    {
        if (!is_array($value)) {
            throw InvalidAiOutputException::fromInvalidType('personal', 'array');
        }

        foreach (self::PERSONAL_REQUIRED as $key) {
            if (!array_key_exists($key, $value)) {
                throw InvalidAiOutputException::fromMissingKey("personal.$key");
            }
        }

        $personal = [];
        foreach (self::PERSONAL_REQUIRED as $key) {
            $personal[$key] = is_string($value[$key]) ? $value[$key] : '';
        }
        foreach (self::PERSONAL_OPTIONAL as $key) {
            $personal[$key] = (isset($value[$key]) && is_string($value[$key])) ? $value[$key] : null;
        }

        return $personal;
    }

    /**
     * @param callable(mixed): array<string, mixed> $coerce
     *
     * @return list<array<string, mixed>>
     */
    private function validateArrayOf(mixed $value, string $key, callable $coerce): array
    {
        if (!is_array($value)) {
            throw InvalidAiOutputException::fromInvalidType($key, 'array');
        }

        return array_values(array_map($coerce, $value));
    }

    /** @return list<string> */
    private function validateStringArray(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw InvalidAiOutputException::fromInvalidType($key, 'array');
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v) => is_string($v) ? $v : null,
            $value,
        ), static fn (mixed $v) => null !== $v));
    }

    /** @return array<string, mixed> */
    private function coerceExperience(mixed $item): array
    {
        if (!is_array($item)) {
            return ['title' => '', 'company' => '', 'start' => null, 'end' => null, 'current' => false, 'description' => null];
        }

        return [
            'title' => is_string($item['title'] ?? null) ? $item['title'] : '',
            'company' => is_string($item['company'] ?? null) ? $item['company'] : '',
            'start' => $this->coerceDate($item['start'] ?? null),
            'end' => $this->coerceDate($item['end'] ?? null),
            'current' => (bool) ($item['current'] ?? false),
            'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function coerceEducation(mixed $item): array
    {
        if (!is_array($item)) {
            return ['degree' => '', 'institution' => '', 'start' => null, 'end' => null];
        }

        return [
            'degree' => is_string($item['degree'] ?? null) ? $item['degree'] : '',
            'institution' => is_string($item['institution'] ?? null) ? $item['institution'] : '',
            'start' => $this->coerceDate($item['start'] ?? null),
            'end' => $this->coerceDate($item['end'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function coerceLanguage(mixed $item): array
    {
        if (!is_array($item)) {
            return ['language' => '', 'level' => null];
        }

        return [
            'language' => is_string($item['language'] ?? null) ? $item['language'] : '',
            'level' => is_string($item['level'] ?? null) ? $item['level'] : null,
        ];
    }

    private function coerceDate(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        // Accept YYYY-MM or YYYY
        if (preg_match('/^\d{4}(-\d{2})?$/', $value)) {
            return 4 === strlen($value) ? $value.'-01' : $value;
        }

        return null;
    }
}
