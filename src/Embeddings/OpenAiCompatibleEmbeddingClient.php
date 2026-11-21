<?php

namespace Filament\ChatAi\Embeddings;

use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiModel;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiCompatibleEmbeddingClient implements EmbeddingClient
{
    public function embed(AiModel $model, string|array $input): array
    {
        $connection = $model->providerConnection;
        $defaults = LlmConfig::forProvider(LlmConfig::PROVIDER_OPENAI);
        $payload = ['model' => $model->model, 'input' => $input, 'encoding_format' => 'float'];
        if ($model->dimensions !== null) {
            $payload['dimensions'] = $model->dimensions;
        }

        try {
            $request = Http::acceptJson()->timeout($defaults->timeout)->withoutRedirecting();
            $key = filled($connection->api_key) ? $connection->api_key : $defaults->apiKey;
            if (filled($key)) {
                $request->withToken($key);
            }
            $url = filled($connection->base_url) ? $connection->base_url : $defaults->baseUrl;
            $response = $request->post(rtrim($url, '/').'/embeddings', $payload);
            if (! $response->successful()) {
                throw new EmbeddingException('Embedding provider request failed.');
            }
            $body = $response->json();
        } catch (Throwable) {
            // Do not retain the original exception: it can contain credentials or request data.
            throw new EmbeddingException('Embedding provider request failed.');
        }

        if (! is_array($body)) {
            throw new EmbeddingException('Invalid embedding provider response.');
        }

        return $body;
    }
}
