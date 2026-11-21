<?php

namespace Filament\ChatAi\Embeddings;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class EmbeddingContext
{
    public function __construct(
        public ?Authenticatable $user = null,
        public ?string $panelId = null,
        public ?string $tenantId = null,
    ) {}

    public static function current(): self
    {
        return new self(
            user: Filament::getCurrentPanel() ? Filament::auth()->user() : auth()->user(),
            panelId: Filament::getCurrentPanel()?->getId(),
            tenantId: Filament::getTenant() ? (string) Filament::getTenant()->getKey() : null,
        );
    }
}
