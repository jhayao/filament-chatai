<?php

use Filament\ChatAi\Embeddings\EmbeddingClient;
use Filament\ChatAi\Embeddings\EmbeddingContext;
use Filament\ChatAi\Embeddings\EmbeddingException;
use Filament\ChatAi\Embeddings\EmbeddingService;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\AiConnection;
use Filament\ChatAi\Models\AiModel;
use Filament\ChatAi\Models\Usage;
use Filament\ChatAi\Support\Pricing;
use Filament\ChatAi\Support\UsageReport;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->connection = AiConnection::create(['name' => 'Vectors', 'driver' => 'openai', 'base_url' => 'https://vectors.test/v1/', 'api_key' => 'secret', 'enabled' => true]);
    $this->model = AiModel::create(['connection_id' => $this->connection->id, 'provider' => 'openai', 'model' => 'embed-test', 'type' => 'embedding', 'dimensions' => 2, 'input_price' => 2, 'enabled' => true]);
});

function embeddingResponse(array $extra = []): array
{
    return array_replace(['model' => 'reported-model', 'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]], 'usage' => ['prompt_tokens' => 100]], $extra);
}

it('embeds a string with credentials and dimensions and records exact contextual cost', function () {
    AiModel::create(['provider' => 'openai', 'model' => 'embed-test', 'input_price' => 999]);
    Http::fake(['*' => Http::response(embeddingResponse())]);
    $result = app(EmbeddingService::class)->embed($this->model, 'hello', new EmbeddingContext(panelId: 'jobs', tenantId: '42'));
    expect($result->vectors)->toBe([[0.1, 0.2]])->and($result->modelId)->toBe($this->model->id)
        ->and($result->model)->toBe('reported-model')->and($result->dimensions)->toBe(2)
        ->and($result->inputTokens)->toBe(100)->and($result->cost)->toBe(0.0002);
    Http::assertSent(fn ($r) => $r->url() === 'https://vectors.test/v1/embeddings' && $r->hasHeader('Authorization', 'Bearer secret') && $r['input'] === 'hello' && $r['dimensions'] === 2 && $r['encoding_format'] === 'float');
    $usage = Usage::first();
    expect($usage->kind)->toBe('embedding')->and($usage->connection_id)->toBe($this->connection->id)->and($usage->model)->toBe('embed-test')
        ->and($usage->panel_id)->toBe('jobs')->and($usage->tenant_id)->toBe('42')->and($usage->latency_ms)->toBeGreaterThanOrEqual(0);
    $report = UsageReport::for(now()->startOfDay(), now()->endOfDay());
    expect($report['totals']['questions'])->toBe(0)->and($report['totals']['tokens_in'])->toBe(100)->and($report['totals']['cost'])->toBe(0.0002);
});

it('orders batch vectors by index and leaves missing usage unpriced', function () {
    $this->model->update(['dimensions' => null]);
    Http::fake(['*' => Http::response(embeddingResponse(['data' => [['index' => 1, 'embedding' => [3, 4]], ['index' => 0, 'embedding' => [1, 2]]], 'usage' => null]))]);
    $result = app(EmbeddingService::class)->embed($this->model, ['one', 'two']);
    expect($result->vectors)->toBe([[1, 2], [3, 4]])->and($result->inputTokens)->toBeNull()->and($result->cost)->toBeNull();
    expect(Usage::first()->tokens_in)->toBeNull()->and(Usage::first()->cost)->toBeNull();
    Http::assertSent(fn ($r) => ! isset($r['dimensions']));
});

it('rejects invalid inputs before sending', function ($input) {
    Http::fake();
    expect(fn () => app(EmbeddingService::class)->embed($this->model, $input))->toThrow(EmbeddingException::class);
    Http::assertNothingSent();
})->with(['empty' => '', 'blank' => '  ', 'empty list' => [[]], 'non strings' => [[1]], 'blank item' => [['a', ' ']], 'map' => [['x' => 'a']]]);

it('rejects malformed provider responses', function ($changes) {
    Http::fake(['*' => Http::response(embeddingResponse($changes))]);
    expect(fn () => app(EmbeddingService::class)->embed($this->model, 'hello'))->toThrow(EmbeddingException::class);
    expect(Usage::count())->toBe(0);
})->with([
    'missing data' => [['data' => null]], 'empty data' => [['data' => []]],
    'bad index' => [['data' => [['index' => 1, 'embedding' => [1, 2]]]]],
    'bad vector' => [['data' => [['index' => 0, 'embedding' => ['secret', 2]]]]],
    'wrong dimensions' => [['data' => [['index' => 0, 'embedding' => [1]]]]],
    'missing model' => [['model' => null]], 'bad tokens' => [['usage' => ['prompt_tokens' => -1]]],
]);

it('blocks unavailable models connections drivers and budgets', function ($case) {
    match ($case) {
        'model' => $this->model->update(['enabled' => false]),
        'type' => $this->model->update(['type' => 'chat']),
        'connection' => $this->connection->update(['enabled' => false]),
        'driver' => $this->connection->update(['driver' => 'anthropic']),
        'missing' => $this->model->update(['connection_id' => null]),
        'daily' => $this->connection->update(['daily_limit' => 0]),
        'monthly' => $this->connection->update(['monthly_limit' => 0]),
    };
    Http::fake();
    expect(fn () => app(EmbeddingService::class)->embed($this->model, 'hello'))->toThrow(EmbeddingException::class);
    Http::assertNothingSent();
})->with(['model', 'type', 'connection', 'driver', 'missing', 'daily', 'monthly']);

it('sanitizes HTTP and connection errors', function ($failure) {
    Http::fake($failure === 'http' ? ['*' => Http::response(['secret' => 'input and key'], 401)] : fn () => throw new ConnectionException('input and key'));
    try {
        app(EmbeddingService::class)->embed($this->model, 'private input');
        test()->fail('Expected failure');
    } catch (EmbeddingException $e) {
        expect($e->getMessage())->not->toContain('input', 'key', 'secret')->and($e->getPrevious())->toBeNull();
    }
    expect(Usage::count())->toBe(0);
})->with(['http', 'connection']);

it('defaults old models to chat and excludes embeddings from chat configurations', function () {
    $chat = AiModel::create(['provider' => 'openai', 'model' => 'chat']);
    expect($chat->fresh()->type)->toBe('chat')->and(AiModel::chain()->pluck('id')->all())->toBe([$chat->id]);
    expect(fn () => LlmConfig::fromModels([$this->model]))->toThrow(InvalidArgumentException::class);
});

it('uses endpoint and key fallback and records missing pricing without guessing', function () {
    $this->connection->update(['base_url' => null, 'api_key' => null]);
    $this->model->update(['input_price' => null]);
    config(['chatai.base_url' => 'https://fallback.test/v1', 'chatai.api_key' => 'fallback-key']);
    Http::fake(['*' => Http::response(embeddingResponse())]);
    $result = app(EmbeddingService::class)->embed($this->model, 'hello');
    expect($result->inputTokens)->toBe(100)->and($result->cost)->toBeNull()->and(Usage::first()->cost)->toBeNull();
    Http::assertSent(fn ($r) => $r->url() === 'https://fallback.test/v1/embeddings' && $r->hasHeader('Authorization', 'Bearer fallback-key'));
});

it('rejects duplicate indices inconsistent dimensions and nonfinite numbers', function ($data) {
    $this->model->update(['dimensions' => null]);
    app()->bind(EmbeddingClient::class, fn () => new class($data) implements EmbeddingClient
    {
        public function __construct(private array $data) {}

        public function embed(AiModel $model, string|array $input): array
        {
            return ['model' => 'vectors', 'data' => $this->data];
        }
    });
    expect(fn () => app(EmbeddingService::class)->embed($this->model, ['a', 'b']))->toThrow(EmbeddingException::class);
})->with([
    'duplicates' => [[['index' => 0, 'embedding' => [1]], ['index' => 0, 'embedding' => [2]]]],
    'inconsistent' => [[['index' => 0, 'embedding' => [1]], ['index' => 1, 'embedding' => [2, 3]]]],
    'infinity' => [[['index' => 0, 'embedding' => [INF]], ['index' => 1, 'embedding' => [2]]]],
]);

it('attributes the default authenticated Filament context', function () {
    $user = User::create(['name' => 'Ana', 'email' => 'embed@example.com', 'password' => 'x']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Http::fake(['*' => Http::response(embeddingResponse())]);
    app(EmbeddingService::class)->embed($this->model, 'hello');
    expect(Usage::first()->user_id)->toBe($user->id)->and(Usage::first()->panel_id)->toBe('admin');
});

it('reverses the embedding migration including unknown token usage', function () {
    Http::fake(['*' => Http::response(embeddingResponse(['usage' => null]))]);
    app(EmbeddingService::class)->embed($this->model, 'hello');
    $migration = require __DIR__.'/../../database/migrations/update_chatai_embeddings_table.php';
    $migration->down();
    expect(Schema::hasColumn('chatai_models', 'type'))->toBeFalse()
        ->and(Usage::first()->tokens_in)->toBe(0);
    $migration->up();
    expect($this->model->fresh()->type)->toBe('chat');
});

it('combines chat and embedding costs in provider limits without counting extra questions', function () {
    $this->connection->update(['daily_limit' => 1]);
    Usage::record(null, Usage::KIND_CHAT, ['connection_id' => $this->connection->id, 'model' => 'chat', 'cost' => 0.9998, 'tokens_in' => 1]);
    Http::fake(['*' => Http::response(embeddingResponse())]);
    app(EmbeddingService::class)->embed($this->model, 'hello');
    expect(fn () => app(EmbeddingService::class)->embed($this->model, 'hello'))->toThrow(EmbeddingException::class);
    $report = UsageReport::for(now()->startOfDay(), now()->endOfDay());
    expect($report['totals']['questions'])->toBe(1)->and($report['totals']['cost'])->toBe(1.0)->and($report['totals']['tokens_in'])->toBe(101);
    Http::assertSentCount(1);
});

it('does not use embedding prices for chat', function () {
    $this->model->update(['output_price' => 50]);
    expect(Pricing::chat('embed-test', 100, 100))->toBeNull();
});

it('uses the configured request timeout and disables redirects', function () {
    config(['chatai.timeout' => 17]);
    Http::fake(function ($request, $options) {
        expect($options['timeout'])->toBe(17)->and($options['allow_redirects'])->toBeFalse();

        return Http::response(embeddingResponse());
    });
    app(EmbeddingService::class)->embed($this->model, 'hello');
});
