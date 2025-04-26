<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class OpenAiService
{
    protected Client $client;
    protected string $apiKey;
    protected string $apiUrl;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiKey = (string) config('services.openai.api_key');
        $this->apiUrl = config('services.openai.api_url');
    }

    /**
     * @param array<int, array<string, string>> $messages
     * @param string $model
     * @param int $maxTokens
     * @return array<string, mixed>
     */
    public function chat(array $messages, string $model = 'gpt-4o-mini', int $maxTokens = 100): array
    {
        try {
            /** @var ResponseInterface $response */
            $response = $this->client->post($this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                ],
            ]);

            $body = (string) $response->getBody();

            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
