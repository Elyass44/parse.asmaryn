<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Parsing\Exception\AiProviderException;
use App\Domain\Parsing\Service\AiProviderInterface;
use App\Domain\Parsing\ValueObject\CleanedText;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DDD note: this class is an Infrastructure adapter — it translates between
 * the domain's AiProviderInterface and the Mistral HTTP API. All Mistral-specific
 * details (endpoint, headers, JSON mode, model name) are contained here.
 * The Application layer only knows AiProviderInterface; swapping this for an
 * OpenAI adapter requires zero changes outside this file and services.yaml.
 */
final readonly class MistralProvider implements AiProviderInterface
{
    private const string ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';
    private const int    TIMEOUT = 30;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $model,
    ) {
    }

    /** @return array<string, mixed> */
    public function extract(CleanedText $text): array
    {
        try {
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
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!is_string($content)) {
                throw AiProviderException::fromResponse($statusCode, 'Missing content in response.');
            }

            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                throw AiProviderException::fromResponse($statusCode, 'Response content is not valid JSON.');
            }

            return $decoded;
        } catch (TimeoutExceptionInterface) {
            throw AiProviderException::fromTimeout();
        }
    }
}
