<?php

use App\Models\TranslationTurn;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    configureAIProviderForTests(['retention_days' => 30]);
    Storage::fake('local');
});

it('removes expired turns and private audio files', function () {
    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create('visitor-a', 'Hello');
    $store->setTranslation($workflow['id'], '翻訳');
    $path = $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    expect($store->find($workflow['id']))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    TranslationTurn::query()->whereKey($workflow['id'])->update([
        'expires_at' => now()->subMinute(),
    ]);

    Artisan::call('translations:prune');

    expect($store->find($workflow['id']))->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('keeps only the latest history limit turns per visitor', function () {
    configureAIProviderForTests(['history_limit' => 2]);

    $store = app(TranslationWorkflowStore::class);
    $first = $store->create('visitor-a', 'One');
    $second = $store->create('visitor-a', 'Two');
    $third = $store->create('visitor-a', 'Three');

    expect($store->find($first['id']))->toBeNull()
        ->and($store->find($second['id']))->not->toBeNull()
        ->and($store->find($third['id']))->not->toBeNull()
        ->and($store->listForVisitor('visitor-a'))->toHaveCount(2);
});
