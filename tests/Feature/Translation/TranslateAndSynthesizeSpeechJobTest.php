<?php

use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\AIProviderSpeechService;
use App\Services\AIProviderTranslationService;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    configureAIProviderForTests();
    Storage::fake('local');
});

it('completes the happy path with Japanese translation and stored WAV audio', function () {
    fakeAIProviders('こんにちは世界');

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello world');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    $completed = $store->find($workflow['id']);

    expect($completed['status'])->toBe('completed')
        ->and($completed['translation'])->toBe('こんにちは世界')
        ->and($completed['stream_debug'])->toContain('accumulated: こんにちは世界')
        ->and($completed['worker_logs'])->toContain('Worker picked up job')
        ->and($completed['worker_logs'])->toContain('Starting AIProvider translation')
        ->and($completed['worker_logs'])->toContain('Starting Fish Audio speech synthesis')
        ->and($completed['worker_logs'])->toContain('Workflow completed')
        ->and($completed['audio_path'])->not->toBeNull()
        ->and(Storage::disk('local')->exists($completed['audio_path']))->toBeTrue();
});

it('sends the Japanese translation to TTS not the English source text', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('翻訳結果'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            fakeWavBytes(),
            200,
            ['Content-Type' => 'audio/wav'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Do not send this English source');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech')) {
            return false;
        }

        return ($request->data()['text'] ?? null) === '翻訳結果'
            && ($request->data()['text'] ?? null) !== 'Do not send this English source';
    });
});

it('uses the configured Gemma translation model', function () {
    fakeAIProviders();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'chat/completions')) {
            return false;
        }

        return ($request->data()['model'] ?? null) === 'google/gemma-4-31b-it'
            && ($request->data()['stream'] ?? null) === true;
    });
});

it('uses the Korean system prompt and still synthesizes speech for non-Japanese targets', function () {
    fakeAIProviders('안녕하세요');

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello world', 'ko');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    $completed = $store->find($workflow['id']);

    expect($completed['status'])->toBe('completed')
        ->and($completed['target_language'])->toBe('ko')
        ->and($completed['translation'])->toBe('안녕하세요')
        ->and($completed['audio_path'])->not->toBeNull();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'chat/completions')) {
            return false;
        }

        $messages = $request->data()['messages'] ?? [];
        $system = $messages[0]['content'] ?? '';

        return is_string($system) && str_contains($system, 'Korean');
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech')) {
            return false;
        }

        return ($request->data()['text'] ?? null) === '안녕하세요';
    });
});

it('uses the Cebuano system prompt and synthesizes speech for Cebuano targets', function () {
    fakeAIProviders('Kumusta mga higala');

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello friends', 'ceb');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    $completed = $store->find($workflow['id']);

    expect($completed['status'])->toBe('completed')
        ->and($completed['target_language'])->toBe('ceb')
        ->and($completed['translation'])->toBe('Kumusta mga higala')
        ->and($completed['audio_path'])->not->toBeNull();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'chat/completions')) {
            return false;
        }

        $messages = $request->data()['messages'] ?? [];
        $system = $messages[0]['content'] ?? '';

        return is_string($system) && str_contains($system, 'Cebuano');
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech')) {
            return false;
        }

        return ($request->data()['text'] ?? null) === 'Kumusta mga higala';
    });
});

it('marks the workflow failed with a safe message when translation HTTP fails', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response('error', 502),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    $failed = $store->find($workflow['id']);

    expect($failed['status'])->toBe('failed')
        ->and($failed['error'])->toBe('Translation failed. Please try again.')
        ->and($failed['error'])->not->toContain('test-api-key')
        ->and($failed['worker_logs'])->toContain('Attempt failed')
        ->and($failed['worker_logs'])->toContain('Job failed permanently');
});

it('marks the workflow failed when translation response is malformed', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            "data: {not-json}\n\ndata: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    expect($store->find($workflow['id'])['status'])->toBe('failed');
});

it('marks the workflow failed when translation response is empty', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            "data: {\"choices\":[{\"delta\":{\"content\":\"   \"}}]}\n\ndata: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    expect($store->find($workflow['id'])['status'])->toBe('failed');
});

it('marks the workflow failed after translation when TTS fails but keeps the translation', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('保存された翻訳'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response('error', 500),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    $failed = $store->find($workflow['id']);

    expect($failed['status'])->toBe('failed')
        ->and($failed['translation'])->toBe('保存された翻訳')
        ->and($failed['error'])->toBe('Speech synthesis failed. Please try again.');
});

it('skips re-translating when a translation is already stored', function () {
    Http::fake([
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            fakeWavBytes(),
            200,
            ['Content-Type' => 'audio/wav'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');
    $store->setTranslation($workflow['id'], '既存の翻訳');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech'));

    expect($store->find($workflow['id'])['status'])->toBe('completed');
});

it('skips TTS when audio is already stored', function () {
    Http::fake();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');
    $store->setTranslation($workflow['id'], '既存の翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertNothingSent();
    expect($store->find($workflow['id'])['status'])->toBe('completed');
});

it('fails clearly when the AIProvider API key is missing', function () {
    configureAIProviderForTests(['api_key' => '']);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    expect($store->find($workflow['id'])['error'])
        ->toBe('Translation service is not configured. Please try again later.');
});

it('sends the captured turn speaker reference id to the TTS request', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('翻訳'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            fakeWavBytes(),
            200,
            ['Content-Type' => 'audio/wav'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello', 'ja', 'turn-captured-voice');

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech')) {
            return false;
        }

        return ($request->data()['reference_id'] ?? null) === 'turn-captured-voice';
    });
});

it('falls back to the global fish reference when turn speaker capture is null', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('翻訳'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            fakeWavBytes(),
            200,
            ['Content-Type' => 'audio/wav'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello', 'ja', null);

    (new TranslateAndSynthesizeSpeech($workflow['id']))->handle(
        $store,
        app(AIProviderTranslationService::class),
        app(AIProviderSpeechService::class),
    );

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'fish-audio-s2-pro-text-to-speech')) {
            return false;
        }

        return ($request->data()['reference_id'] ?? null) === 'test-fish-ref';
    });
});

it('redacts reference ids from worker logs when synthesis fails', function () {
    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('翻訳'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
        'https://api.aiprovider.test/v3/fish-audio-s2-pro-text-to-speech' => Http::response(
            'reference_id=secret-voice-42 failed',
            500,
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello', 'ja', 'secret-voice-42');

    runJobToFailure($workflow['id']);

    $failed = $store->find($workflow['id']);

    expect($failed['worker_logs'])->not->toContain('secret-voice-42')
        ->and($failed['error'])->not->toContain('secret-voice-42');
});

it('fails clearly when the Fish reference id is missing', function () {
    configureAIProviderForTests(['fish_reference_id' => '']);

    Http::fake([
        'https://api.aiprovider.test/openai/v1/chat/completions' => Http::response(
            fakeAIProviderStreamBody('翻訳'),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');

    runJobToFailure($workflow['id']);

    expect($store->find($workflow['id'])['error'])
        ->toBe('Translation service is not configured. Please try again later.');
});
