<?php

namespace Filament\ChatAi\Embeddings;

final readonly class EmbeddingResult
{
    /** @param list<list<int|float>> $vectors */
    public function __construct(
        public array $vectors,
        public int $modelId,
        public string $model,
        public int $dimensions,
        public ?int $inputTokens,
        public ?float $cost,
    ) {}
}
