<?php

namespace Filament\ChatAi\Embeddings;

use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\Budget;

class EmbeddingService
{
    public function __construct(protected EmbeddingClient $client) {}

    public function embed(AiModel $model, string|array $input, ?EmbeddingContext $context = null): EmbeddingResult
    {
        $items = is_string($input) ? [$input] : $input;
        if (! array_is_list($items) || $items === [] || count(array_filter($items, fn ($item): bool => ! is_string($item) || trim($item) === '')) > 0) {
            throw new EmbeddingException('Provide a string or a nonempty list of nonblank strings.');
        }
        ChatAiSettings::apply();
        $model = $model->fresh(['providerConnection']);
        $connection = $model?->providerConnection;
        if (! $model || $model->type !== 'embedding' || ! $model->enabled || ! $connection?->enabled || $connection->driver !== 'openai') {
            throw new EmbeddingException('Select an enabled embedding model with an enabled OpenAI-compatible provider.');
        }
        if ($model->dimensions !== null && $model->dimensions < 1) {
            throw new EmbeddingException('Embedding dimensions must be positive.');
        }
        if (Budget::exceeded($connection)) {
            throw new EmbeddingException('Embedding provider spending limit reached.');
        }
        $context ??= EmbeddingContext::current();
        $started = hrtime(true);
        $body = $this->client->embed($model, $input);
        [$vectors, $dimensions, $tokens] = $this->validate($body, count($items), $model->dimensions);
        $cost = $tokens !== null && is_numeric($model->input_price)
            ? round($tokens * (float) $model->input_price / 1_000_000, 6) : null;
        Usage::record($context->user, Usage::KIND_EMBEDDING, [
            'panel_id' => $context->panelId, 'tenant_id' => $context->tenantId,
            'connection_id' => $connection->id, 'model' => $model->model,
            'tokens_in' => $tokens, 'cost' => $cost,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
        ]);

        return new EmbeddingResult($vectors, $model->id, $body['model'], $dimensions, $tokens, $cost);
    }

    /** @return array{array, int, ?int} */
    protected function validate(array $body, int $count, ?int $configuredDimensions): array
    {
        $invalid = fn () => new EmbeddingException('Invalid embedding provider response.');
        $data = $body['data'] ?? null;
        if (! is_string($body['model'] ?? null) || trim($body['model']) === '' || ! is_array($data) || ! array_is_list($data) || count($data) !== $count) {
            throw $invalid();
        }
        $vectors = [];
        $dimensions = $configuredDimensions;
        foreach ($data as $row) {
            $index = $row['index'] ?? null;
            $vector = $row['embedding'] ?? null;
            if (! is_int($index) || $index < 0 || $index >= $count || isset($vectors[$index]) || ! is_array($vector) || ! array_is_list($vector) || $vector === []) {
                throw $invalid();
            }
            $dimensions ??= count($vector);
            if (count($vector) !== $dimensions) {
                throw $invalid();
            }
            foreach ($vector as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    throw $invalid();
                }
            }
            $vectors[$index] = $vector;
        }
        $tokens = $body['usage']['prompt_tokens'] ?? null;
        if ($tokens !== null && (! is_int($tokens) || $tokens < 0)) {
            throw $invalid();
        }
        ksort($vectors);

        return [array_values($vectors), $dimensions, $tokens];
    }
}
