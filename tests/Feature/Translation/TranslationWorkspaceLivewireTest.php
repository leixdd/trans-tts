<?php

use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Livewire\TranslationWorkspace;
use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    configureNovitaForTests();
});

it('shows validation errors for empty submit', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('text', '')
        ->call('submit')
        ->assertHasErrors(['text' => 'required']);
});

it('shows validation errors for oversized submit', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('text', str_repeat('a', 10001))
        ->call('submit')
        ->assertHasErrors(['text' => 'max']);
});

it('allows another submit while a turn is still in flight', function () {
    Queue::fake();

    $visitorId = '33333333-3333-4333-8333-333333333333';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('text', 'First')
        ->call('submit')
        ->assertSet('text', '')
        ->set('text', 'Second')
        ->call('submit')
        ->assertCount('turns', 2);

    Queue::assertPushed(TranslateAndSynthesizeSpeech::class, 2);
});

it('exposes SSE stream URLs for in-flight turns and keeps fallback polling', function () {
    Queue::fake();

    $visitorId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    $component = Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('text', 'Stream me')
        ->call('submit');

    $turns = $component->get('turns');
    expect($turns)->toHaveCount(1)
        ->and($turns[0]['stream_url'])->toBe(route('translations.stream', ['workflow' => $turns[0]['id']]));

    $component
        ->assertSee('data-turn-stream=', false)
        ->assertSee('wire:poll.5s="pollStatus"', false);
});

it('restores visitor history after remount', function () {
    $visitorId = '44444444-4444-4444-8444-444444444444';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hello history');
    $store->setTranslation($turn['id'], '履歴');
    $store->storeAudio($turn['id'], fakeWavBytes());
    $store->markCompleted($turn['id']);

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->assertSee('Hello history')
        ->assertSee('履歴')
        ->assertCount('turns', 1);
});

it('polls in-flight turns to completed state and dispatches FIFO audio events', function () {
    $visitorId = '55555555-5555-4555-8555-555555555555';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hello');
    $store->appendStreamDebug(
        $turn['id'],
        '翻訳完了',
        '{"choices":[{"delta":{"content":"翻訳完了"}}]}',
    );
    $store->setTranslation($turn['id'], '翻訳完了');
    $store->storeAudio($turn['id'], fakeWavBytes());
    $store->markCompleted($turn['id']);
    $store->appendWorkerLog($turn['id'], 'Workflow completed');

    $component = Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('turns', [[
            'id' => $turn['id'],
            'status' => 'synthesizing',
            'source_text' => 'Hello',
            'translation' => null,
            'stream_debug' => null,
            'worker_logs' => null,
            'audio_url' => null,
            'stream_url' => route('translations.stream', ['workflow' => $turn['id']]),
            'error' => null,
            'created_at' => now()->toIso8601String(),
        ]])
        ->set('debugTurnId', $turn['id'])
        ->call('pollStatus')
        ->assertDispatched('translation-audio-ready')
        ->assertDontSee('Worker debug logs')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', true)
        ->assertSee('Worker debug logs')
        ->assertSee('Novita stream debug')
        ->assertSee('翻訳完了');

    $turns = $component->get('turns');
    expect($turns[0]['status'])->toBe('completed')
        ->and($turns[0]['audio_url'])->not->toBeNull()
        ->and($turns[0]['stream_debug'])->toContain('accumulated: 翻訳完了')
        ->and($turns[0]['worker_logs'])->toContain('Workflow completed');
});

it('toggles debug log sections via the debug icon', function () {
    Livewire::test(TranslationWorkspace::class)
        ->assertSet('showDebugLogs', false)
        ->assertSee('aria-label="Debug controls"', false)
        ->assertDontSee('Worker debug logs')
        ->assertDontSee('Novita stream debug')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', true)
        ->assertSee('Worker debug logs')
        ->assertSee('Novita stream debug')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', false)
        ->assertDontSee('Worker debug logs')
        ->assertDontSee('Novita stream debug');
});

it('keeps composer empty after submit and surfaces failure in the thread', function () {
    $visitorId = '66666666-6666-4666-8666-666666666666';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Keep this text');
    $store->markFailed($turn['id'], 'Translation failed. Please try again.');

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->assertSee('Keep this text')
        ->assertSee('Translation failed. Please try again.')
        ->assertSet('text', '');
});
