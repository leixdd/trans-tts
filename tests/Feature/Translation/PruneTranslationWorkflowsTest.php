<?php

use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    configureNovitaForTests(['retention_minutes' => 60]);
    Storage::fake('local');
});

it('removes expired workflow cache entries and private audio files', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('session-a', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $path = $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    expect($store->find($workflow['id']))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->travel(61)->minutes();

    Artisan::call('translations:prune');

    expect($store->find($workflow['id']))->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});
