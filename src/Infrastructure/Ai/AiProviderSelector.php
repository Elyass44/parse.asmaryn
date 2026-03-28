<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Parsing\Exception\AiProviderException;
use App\Domain\Parsing\Service\AiProviderInterface;
use App\Domain\Parsing\ValueObject\CleanedText;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DDD note: this selector lives in Infrastructure because it knows about
 * concrete provider adapters (Mistral, OpenAI). It implements AiProviderInterface
 * so the Application layer receives a single, uniform abstraction regardless of
 * which provider is active. Switching providers is a config-only change (AI_PROVIDER env var).
 */
#[AsAlias(AiProviderInterface::class)]
final readonly class AiProviderSelector implements AiProviderInterface
{
    public function __construct(
        private MistralProvider $mistral,
        private OpenAiProvider $openAi,
        #[Autowire(env: 'AI_PROVIDER')] private string $activeProvider,
    ) {
    }

    /** @return array<string, mixed> */
    public function extract(CleanedText $text): array
    {
        return match ($this->activeProvider) {
            'openai' => $this->openAi->extract($text),
            'mistral' => $this->mistral->extract($text),
            default => throw new AiProviderException(sprintf('Unknown AI provider "%s". Supported values: mistral, openai.', $this->activeProvider)),
        };
    }
}
