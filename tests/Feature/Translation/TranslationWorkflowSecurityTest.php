<?php

use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

function signedAudioRequestPath(string $signedUrl): string
{
    $parts = parse_url($signedUrl);

    return ($parts['path'] ?? '').'?'.($parts['query'] ?? '');
}

beforeEach(function () {
    configureNovitaForTests();
    Storage::fake('local');
    config(['app.url' => 'http://localhost']);
    URL::forceRootUrl('http://localhost');
});

it('denies publicStatus to a different session', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-session', 'Hello');

    expect(fn () => $store->publicStatus($workflow['id'], 'other-session'))
        ->toThrow(AccessDeniedHttpException::class);
});

it('denies unsigned audio requests', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-session', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $this->get(route('translations.audio', ['workflow' => $workflow['id']]))
        ->assertForbidden();
});

it('denies signed audio requests from a non-owning session', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-session', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->get(signedAudioRequestPath($url))->assertForbidden();
});

it('streams WAV audio for the owning session with a valid signed URL', function () {
    $this->startSession();
    $sessionId = session()->getId();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($sessionId, 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->withCookie(config('session.cookie'), $sessionId)
        ->get(signedAudioRequestPath($url))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav');
});

it('denies audio access for an expired workflow', function () {
    $this->startSession();
    $sessionId = session()->getId();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($sessionId, 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->travel(61)->minutes();

    $response = $this->withCookie(config('session.cookie'), $sessionId)
        ->get(signedAudioRequestPath($url));

    expect($response->status())->toBeIn([403, 404]);
});
