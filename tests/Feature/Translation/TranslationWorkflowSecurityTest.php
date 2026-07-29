<?php

use App\Services\AnonymousVisitor;
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

it('denies publicStatus to a different visitor', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-visitor', 'Hello');

    expect(fn () => $store->publicStatus($workflow['id'], 'other-visitor'))
        ->toThrow(AccessDeniedHttpException::class);
});

it('denies unsigned audio requests', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-visitor', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $this->get(route('translations.audio', ['workflow' => $workflow['id']]))
        ->assertForbidden();
});

it('denies signed audio requests from a non-owning visitor', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('owner-visitor', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, 'other-visitor')
        ->get(signedAudioRequestPath($url))
        ->assertForbidden();
});

it('streams WAV audio for the owning visitor with a valid signed URL', function () {
    $visitorId = '11111111-1111-4111-8111-111111111111';

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($visitorId, 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->get(signedAudioRequestPath($url))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav');
});

it('denies audio access for an expired turn', function () {
    $visitorId = '22222222-2222-4222-8222-222222222222';

    configureNovitaForTests(['signed_url_minutes' => 60, 'retention_days' => 30]);

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($visitorId, 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    $url = $store->signedAudioUrl($workflow['id']);
    expect($url)->not->toBeNull();

    $this->travel(61)->minutes();

    $response = $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->get(signedAudioRequestPath($url));

    expect($response->status())->toBeIn([403, 404]);
});
