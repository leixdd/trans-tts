<?php

use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Livewire\TranslationWorkspace;
use App\Services\AnonymousVisitor;
use App\Services\TranslationSpeakerCatalog;
use App\Services\TranslationToneCatalog;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    configureAIProviderForTests();
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

it('defaults the target language to Japanese and persists a new selection in session', function () {
    Livewire::test(TranslationWorkspace::class)
        ->assertSet('targetLanguage', 'ja')
        ->set('targetLanguage', 'zh')
        ->assertSet('targetLanguage', 'zh');

    expect(session('translation_target_language'))->toBe('zh');

    Livewire::test(TranslationWorkspace::class)
        ->assertSet('targetLanguage', 'zh');
});

it('rejects unsupported target languages on submit', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('targetLanguage', 'xx')
        ->call('submit')
        ->assertHasErrors(['targetLanguage']);
});

it('stores the selected target language on each submitted turn and shows it in the bubble', function () {
    Queue::fake();

    $visitorId = '22222222-2222-4222-8222-222222222222';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('targetLanguage', 'en')
        ->set('text', 'Bonjour')
        ->call('submit')
        ->assertSee('English')
        ->assertSee('data-turn-target-language="en"', false);

    $turns = app(TranslationWorkflowStore::class)->listForVisitor($visitorId);

    expect($turns)->toHaveCount(1)
        ->and($turns[0]['target_language'])->toBe('en')
        ->and($turns[0]['target_language_label'])->toBe('English');
});

it('stores Cebuano as a supported target language on submitted turns', function () {
    Queue::fake();

    $visitorId = '22222222-2222-4222-8222-222222222223';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->assertSee('Cebuano')
        ->set('targetLanguage', 'ceb')
        ->set('text', 'Hello friends')
        ->call('submit')
        ->assertSee('Cebuano')
        ->assertSee('data-turn-target-language="ceb"', false);

    $turns = app(TranslationWorkflowStore::class)->listForVisitor($visitorId);

    expect($turns)->toHaveCount(1)
        ->and($turns[0]['target_language'])->toBe('ceb')
        ->and($turns[0]['target_language_label'])->toBe('Cebuano');
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

it('exposes stable data hooks on the chat card and scroll container', function () {
    Livewire::test(TranslationWorkspace::class)
        ->assertSee('data-translation-chat', false)
        ->assertSee('data-translation-scroll', false)
        ->assertSee('data-translation-workspace', false);
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
            'target_language' => 'ja',
            'target_language_label' => 'Japanese',
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
        ->assertSee('AIProvider stream debug')
        ->assertSee('翻訳完了');

    $turns = $component->get('turns');
    expect($turns[0]['status'])->toBe('completed')
        ->and($turns[0]['audio_url'])->not->toBeNull()
        ->and($turns[0]['stream_debug'])->toContain('accumulated: 翻訳完了')
        ->and($turns[0]['worker_logs'])->toContain('Workflow completed');
});

it('toggles debug log sections via the debug icon', function () {
    config(['translation.debug_toolbar_enabled' => true]);

    Livewire::test(TranslationWorkspace::class)
        ->assertSet('showDebugLogs', false)
        ->assertSee('aria-label="Debug controls"', false)
        ->assertDontSee('Worker debug logs')
        ->assertDontSee('AIProvider stream debug')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', true)
        ->assertSee('Worker debug logs')
        ->assertSee('AIProvider stream debug')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', false)
        ->assertDontSee('Worker debug logs')
        ->assertDontSee('AIProvider stream debug');
});

it('hides the debug toolbar and no-ops debug actions when disabled via config', function () {
    config(['translation.debug_toolbar_enabled' => false]);

    $visitorId = '77777777-7777-4777-8777-777777777777';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hidden debug');

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->assertDontSee('aria-label="Debug controls"', false)
        ->assertDontSee('>Debug</button>', false)
        ->assertDontSee('Worker debug logs')
        ->assertDontSee('AIProvider stream debug')
        ->call('toggleDebugLogs')
        ->assertSet('showDebugLogs', false)
        ->assertDontSee('Worker debug logs')
        ->call('selectDebugTurn', $turn['id'])
        ->assertSet('showDebugLogs', false)
        ->assertSet('debugTurnId', null);
});

it('defaults speaker mode to system and exposes the settings toggle', function () {
    Livewire::test(TranslationWorkspace::class)
        ->assertSet('speakerMode', TranslationSpeakerCatalog::MODE_SYSTEM)
        ->assertSee('data-speaker-settings-toggle', false)
        ->assertSee('System default')
        ->assertSee('Custom reference ID');
});

it('persists speaker mode and custom reference id in session across remount', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->set('customReferenceId', 'session-voice-01')
        ->assertSet('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->assertSet('customReferenceId', 'session-voice-01');

    expect(session('translation_speaker_mode'))->toBe(TranslationSpeakerCatalog::MODE_CUSTOM)
        ->and(session('translation_speaker_custom_reference_id'))->toBe('session-voice-01');

    Livewire::test(TranslationWorkspace::class)
        ->assertSet('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->assertSet('customReferenceId', 'session-voice-01');
});

it('rejects submit with custom mode and empty custom reference id without dispatching', function () {
    Queue::fake();

    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->set('customReferenceId', '')
        ->call('submit')
        ->assertHasErrors(['customReferenceId']);

    Queue::assertNothingPushed();
});

it('rejects submit with invalid custom reference id without dispatching', function () {
    Queue::fake();

    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->set('customReferenceId', 'bad id')
        ->call('submit')
        ->assertHasErrors(['customReferenceId']);

    Queue::assertNothingPushed();
});

it('omits speaker reference id from public turn payloads', function () {
    Queue::fake();

    $visitorId = '88888888-8888-4888-8888-888888888888';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('speakerMode', TranslationSpeakerCatalog::MODE_CUSTOM)
        ->set('customReferenceId', 'public-hidden-voice')
        ->call('submit');

    $turns = app(TranslationWorkflowStore::class)->listForVisitor($visitorId);

    expect($turns)->toHaveCount(1)
        ->and($turns[0])->not->toHaveKey('speaker_reference_id')
        ->and(collect($turns[0])->keys())->not->toContain('speaker_reference_id');

    $public = app(TranslationWorkflowStore::class)->publicStatus($turns[0]['id'], $visitorId);

    expect($public)->not->toHaveKey('speaker_reference_id');
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

it('defaults translation tone to normal and exposes tone options', function () {
    Livewire::test(TranslationWorkspace::class)
        ->assertSet('translationTone', TranslationToneCatalog::CODE_NORMAL)
        ->assertSee('Normal Mode')
        ->assertSee('Business Mode')
        ->assertSee('Academic Mode')
        ->assertSee('data-translation-tone', false)
        ->assertSee('id="translation-tone"', false);
});

it('persists translation tone in session across remount', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('translationTone', TranslationToneCatalog::CODE_BUSINESS)
        ->assertSet('translationTone', TranslationToneCatalog::CODE_BUSINESS);

    expect(session('translation_tone'))->toBe(TranslationToneCatalog::CODE_BUSINESS);

    Livewire::test(TranslationWorkspace::class)
        ->assertSet('translationTone', TranslationToneCatalog::CODE_BUSINESS);
});

it('rejects unsupported translation tone on submit without dispatching', function () {
    Queue::fake();

    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('translationTone', 'casual')
        ->call('submit')
        ->assertHasErrors(['translationTone']);

    Queue::assertNothingPushed();
});

it('captures the selected translation tone on the turn at submission', function () {
    Queue::fake();

    $visitorId = '99999999-9999-4999-8999-999999999999';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('translationTone', TranslationToneCatalog::CODE_ACADEMIC)
        ->call('submit');

    $workflow = app(TranslationWorkflowStore::class)->listForVisitor($visitorId);
    $turn = app(TranslationWorkflowStore::class)->find($workflow[0]['id']);

    expect($turn['translation_tone'])->toBe(TranslationToneCatalog::CODE_ACADEMIC);
});

it('omits translation tone from public turn payloads', function () {
    Queue::fake();

    $visitorId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab';

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('translationTone', TranslationToneCatalog::CODE_BUSINESS)
        ->call('submit');

    $turns = app(TranslationWorkflowStore::class)->listForVisitor($visitorId);

    expect($turns)->toHaveCount(1)
        ->and($turns[0])->not->toHaveKey('translation_tone')
        ->and(collect($turns[0])->keys())->not->toContain('translation_tone');

    $public = app(TranslationWorkflowStore::class)->publicStatus($turns[0]['id'], $visitorId);

    expect($public)->not->toHaveKey('translation_tone');
});

it('keeps the turn tone snapshot immutable after later submissions with different tone', function () {
    Queue::fake();

    $visitorId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $store = app(TranslationWorkflowStore::class);

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('translationTone', TranslationToneCatalog::CODE_BUSINESS)
        ->set('text', 'First')
        ->call('submit');

    $firstTurnId = \App\Models\TranslationTurn::query()
        ->where('visitor_id', $visitorId)
        ->where('source_text', 'First')
        ->value('id');

    Livewire::withCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->test(TranslationWorkspace::class)
        ->set('translationTone', TranslationToneCatalog::CODE_ACADEMIC)
        ->assertSet('translationTone', TranslationToneCatalog::CODE_ACADEMIC)
        ->set('text', 'Second')
        ->call('submit');

    $secondTurnId = \App\Models\TranslationTurn::query()
        ->where('visitor_id', $visitorId)
        ->where('source_text', 'Second')
        ->value('id');

    expect($store->find((string) $firstTurnId)['translation_tone'])->toBe(TranslationToneCatalog::CODE_BUSINESS)
        ->and($store->find((string) $secondTurnId)['translation_tone'])->toBe(TranslationToneCatalog::CODE_ACADEMIC);
});
