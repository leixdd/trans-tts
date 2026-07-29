<?php

use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\NovitaSpeechService;
use App\Services\NovitaTranslationService;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function configureNovitaForTests(array $overrides = []): void
{
    config([
        'services.novita.api_key' => $overrides['api_key'] ?? 'test-api-key',
        'services.novita.chat_base_url' => $overrides['chat_base_url'] ?? 'https://api.novita.test/openai/v1',
        'services.novita.tts_endpoint' => $overrides['tts_endpoint'] ?? 'https://api.novita.test/v3/fish-audio-s2-pro-text-to-speech',
        'services.novita.translation_model' => $overrides['translation_model'] ?? 'google/gemma-4-31b-it',
        'services.novita.fish_reference_id' => $overrides['fish_reference_id'] ?? 'test-fish-ref',
        'services.novita.timeout' => $overrides['timeout'] ?? 60,
        'services.novita.retention_minutes' => $overrides['retention_minutes'] ?? 60,
        'services.novita.user_agent' => $overrides['user_agent'] ?? 'tts-app-test/1.0',
    ]);
}

function fakeWavBytes(): string
{
    return 'RIFF'.str_repeat("\0", 100);
}

function fakeNovitaProviders(string $translation = 'こんにちは'): void
{
    Http::fake([
        'https://api.novita.test/openai/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => $translation]],
            ],
        ]),
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
