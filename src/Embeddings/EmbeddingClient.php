<?php

namespace Filament\ChatAi\Embeddings;

use Filament\ChatAi\Models\AiModel;

interface EmbeddingClient
{
    /** @return array<string, mixed> */
    public function embed(AiModel $model, string|array $input): array;
}
