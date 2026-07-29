<?php

use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\AnonymousVisitor;
use App\Services\NovitaSpeechService;
use App\Services\NovitaTranslationService;
use App\Services\TranslationWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

function configureNovitaForTests(array $overrides = []): void
{
    config([
        'services.novita.api_key' => $overrides['api_key'] ?? 'test-api-key',
        'services.novita.chat_base_url' => $overrides['chat_base_url'] ?? 'https://api.novita.test/openai/v1',
        'services.novita.tts_endpoint' => $overrides['tts_endpoint'] ?? 'https://api.novita.test/v3/fish-audio-s2-pro-text-to-speech',
        'services.novita.translation_model' => $overrides['translation_model'] ?? 'google/gemma-4-31b-it',
        'services.novita.fish_reference_id' => $overrides['fish_reference_id'] ?? 'test-fish-ref',
        'services.novita.timeout' => $overrides['timeout'] ?? 60,
        'services.novita.retention_days' => $overrides['retention_days'] ?? 30,
        'services.novita.history_limit' => $overrides['history_limit'] ?? 50,
        'services.novita.signed_url_minutes' => $overrides['signed_url_minutes'] ?? 60,
        'services.novita.stream_poll_seconds' => $overrides['stream_poll_seconds'] ?? 0.1,
        'services.novita.stream_heartbeat_seconds' => $overrides['stream_heartbeat_seconds'] ?? 15,
        'services.novita.stream_max_seconds' => $overrides['stream_max_seconds'] ?? 2,
        'services.novita.user_agent' => $overrides['user_agent'] ?? 'tts-app-test/1.0',
    ]);
}

function fakeWavBytes(): string
{
    return 'RIFF'.str_repeat("\0", 100);
}

function fakeNovitaStreamBody(string $translation): string
{
    $chunks = preg_split('//u', $translation, -1, PREG_SPLIT_NO_EMPTY);

    if ($chunks === false || $chunks === []) {
        $chunks = [$translation];
    }

    $events = [];

    foreach (array_chunk($chunks, max(1, (int) ceil(count($chunks) / 3))) as $piece) {
        $payload = json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => implode('', $piece)],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $events[] = 'data: '.$payload;
    }

    $events[] = 'data: [DONE]';

    return implode("\n\n", $events)."\n\n";
}

function fakeNovitaProviders(string $translation = 'こんにちは'): void
{
    Http::fake([
        'https://api.novita.test/openai/v1/chat/completions' => Http::response(
            fakeNovitaStreamBody($translation),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.novita.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            fakeWavBytes(),
            200,
            ['Content-Type' => 'audio/wav'],
        ),
    ]);
}

function runJobToFailure(string $workflowId): void
{
    $job = new TranslateAndSynthesizeSpeech($workflowId);

    try {
        $job->handle(
            app(TranslationWorkflowStore::class),
            app(NovitaTranslationService::class),
            app(NovitaSpeechService::class),
        );
    } catch (Throwable $exception) {
        $job->failed($exception);
    }
}

function withVisitorCookie(string $visitorId): array
{
    return [AnonymousVisitor::COOKIE_NAME => $visitorId];
}
