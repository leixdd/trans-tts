<?php

use App\Livewire\TranslationWorkspace;
use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;
use Livewire\Livewire;

beforeEach(function () {
    configureAIProviderForTests();
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

it('dispatches audio-ready for a later turn that finishes while an earlier turn is still in flight', function () {
    $visitorId = '88888888-8888-4888-8888-888888888888';
    $store = app(TranslationWorkflowStore::class);

    $this->travel(-1)->minute();
    $first = $store->create($visitorId, 'First');
    $this->travelBack();
    $second = $store->create($visitorId, 'Second');
    $store->setTranslation($second['id'], '二番目');
    $store->storeAudio($second['id'], fakeWavBytes());
    $store->markCompleted($second['id']);

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('turns', [
            [
                'id' => $first['id'],
                'status' => 'translating',
                'source_text' => 'First',
                'translation' => null,
                'stream_debug' => null,
                'worker_logs' => null,
                'audio_url' => null,
                'stream_url' => route('translations.stream', ['workflow' => $first['id']]),
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
        ->assertDispatched('translation-playback-sync')
        ->assertDispatched('translation-audio-ready', function ($eventName, $params) use ($second): bool {
            $id = $params['id'] ?? $params[0]['id'] ?? null;
            $url = $params['url'] ?? $params[0]['url'] ?? null;

            return $id === $second['id'] && is_string($url) && $url !== '';
        })
        ->assertNotDispatched('translation-playback-failed');

    $turns = $store->listForVisitor($visitorId);

    expect(array_column($turns, 'id'))->toBe([$first['id'], $second['id']])
        ->and($turns[0]['status'])->not->toBe('completed')
        ->and($turns[1]['status'])->toBe('completed')
        ->and($turns[1]['audio_url'])->not->toBeNull();
});

it('renders custom FIFO playback controls without native audio volume UI', function () {
    $visitorId = '99999999-9999-4999-8999-999999999999';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Ready speech');
    $store->setTranslation($turn['id'], '準備');
    $store->storeAudio($turn['id'], fakeWavBytes());
    $store->markCompleted($turn['id']);

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->assertSee('data-playback-shell="'.$turn['id'].'"', false)
        ->assertSee('data-playback-toggle="'.$turn['id'].'"', false)
        ->assertSee('data-audio-src=', false)
        ->assertDontSee('<audio', false)
        ->assertDontSee('<audio controls', false);
});
