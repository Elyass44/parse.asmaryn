<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Parsing\Exception\AiProviderException;
use App\Domain\Parsing\Service\AiProviderInterface;
use App\Domain\Parsing\ValueObject\CleanedText;
use App\Domain\Parsing\ValueObject\ExtractionResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DDD note: Infrastructure adapter for the OpenAI chat completions API.
 * The request/response shape is identical to Mistral's, so ExtractionPrompt
 * is shared. Only the endpoint and credential format differ — everything
 * above this layer (Application, Domain) is completely unaware of that.
 */
final readonly class OpenAiProvider implements AiProviderInterface
{
    private const string ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const int    TIMEOUT = 30;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'OPENAI_API_KEY')] private string $apiKey,
        #[Autowire(env: 'OPENAI_MODEL')] private string $model,
    ) {
    }

    public function extract(CleanedText $text): ExtractionResult
    {
        try {
            $startNs = hrtime(true);
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => ExtractionPrompt::SYSTEM],
                        ['role' => 'user', 'content' => $text->text],
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                throw AiProviderException::fromResponse($statusCode, $response->getContent(false));
            }

            $data = $response->toArray();
            $aiDurationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!is_string($content)) {
                throw AiProviderException::fromResponse($statusCode, 'Missing content in response.');
            }

            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                throw AiProviderException::fromResponse($statusCode, 'Response content is not valid JSON.');
            }

            $usage = $data['usage'] ?? [];

            return new ExtractionResult(
                payload: $decoded,
                tokensPrompt: (int) ($usage['prompt_tokens'] ?? 0),
                tokensCompletion: (int) ($usage['completion_tokens'] ?? 0),
                tokensTotal: (int) ($usage['total_tokens'] ?? 0),
                provider: 'openai',
                aiDurationMs: $aiDurationMs,
            );
        } catch (TimeoutExceptionInterface) {
            throw AiProviderException::fromTimeout();
        }
    }
}
