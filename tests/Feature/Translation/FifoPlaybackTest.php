<?php

use App\Livewire\TranslationWorkspace;
use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;
use Livewire\Livewire;

beforeEach(function () {
    configureNovitaForTests();
});

it('skips failed earlier turns when notifying FIFO playback for a later completion', function () {
    $visitorId = '77777777-7777-4777-8777-777777777777';
    $store = app(TranslationWorkflowStore::class);

    $failed = $store->create($visitorId, 'First');
    $store->markFailed($failed['id'], 'Translation failed. Please try again.');

    $second = $store->create($visitorId, 'Second');
    $store->setTranslation($second['id'], '二番目');
    $store->storeAudio($second['id'], fakeWavBytes());
    $store->markCompleted($second['id']);

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('turns', [
            [
                'id' => $failed['id'],
                'status' => 'translating',
                'source_text' => 'First',
                'translation' => null,
                'stream_debug' => null,
                'worker_logs' => null,
                'audio_url' => null,
                'stream_url' => route('translations.stream', ['workflow' => $failed['id']]),
                'error' => null,
                'created_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'id' => $second['id'],
                'status' => 'synthesizing',
                'source_text' => 'Second',
                'translation' => null,
                'stream_debug' => null,
                'worker_logs' => null,
                'audio_url' => null,
                'stream_url' => route('translations.stream', ['workflow' => $second['id']]),
                'error' => null,
                'created_at' => now()->toIso8601String(),
            ],
        ])
        ->call('pollStatus')
        ->assertDispatched('translation-playback-failed')
        ->assertDispatched('translation-audio-ready')
        ->assertDispatched('translation-playback-sync');
});
