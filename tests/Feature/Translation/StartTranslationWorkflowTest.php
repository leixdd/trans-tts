<?php

use App\Actions\StartTranslationWorkflow;
use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\TranslationSpeakerCatalog;
use App\Services\TranslationToneCatalog;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    configureAIProviderForTests();
});

it('rejects empty text when starting a workflow', function () {
    expect(fn () => app(StartTranslationWorkflow::class)('visitor-a', '', 'ja'))
        ->toThrow(ValidationException::class);
});

it('rejects text longer than 10000 characters when starting a workflow', function () {
    expect(fn () => app(StartTranslationWorkflow::class)('visitor-a', str_repeat('a', 10001), 'ja'))
        ->toThrow(ValidationException::class);
});

it('rejects unsupported target languages when starting a workflow', function () {
    expect(fn () => app(StartTranslationWorkflow::class)('visitor-a', 'Hello', 'xx'))
        ->toThrow(ValidationException::class);
});

it('returns queued status and dispatches the synthesis job on success', function () {
    Queue::fake();

    $result = app(StartTranslationWorkflow::class)('visitor-a', 'Hello world', 'ko');

    expect($result)->toMatchArray([
        'status' => 'queued',
    ]);
    expect($result['id'])->not->toBeEmpty();

    Queue::assertPushed(TranslateAndSynthesizeSpeech::class, function (TranslateAndSynthesizeSpeech $job) use ($result) {
        return $job->workflowId === $result['id'];
    });

    $workflow = app(TranslationWorkflowStore::class)->find($result['id']);
    expect($workflow)->not->toBeNull()
        ->and($workflow['status'])->toBe('queued')
        ->and($workflow['visitor_id'])->toBe('visitor-a')
        ->and($workflow['source_text'])->toBe('Hello world')
        ->and($workflow['target_language'])->toBe('ko')
        ->and($workflow['worker_logs'])->toContain('Turn created')
        ->and($workflow['worker_logs'])->toContain('Job dispatched to queue');
});

it('rejects custom speaker mode without a reference id', function () {
    Queue::fake();

    expect(fn () => app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'ja',
        TranslationSpeakerCatalog::MODE_CUSTOM,
        null,
    ))->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('rejects invalid custom reference id format without dispatching a job', function () {
    Queue::fake();

    expect(fn () => app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'ja',
        TranslationSpeakerCatalog::MODE_CUSTOM,
        'bad id with spaces',
    ))->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('captures the resolved speaker reference id on the turn at submission', function () {
    Queue::fake();

    $result = app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'en',
        TranslationSpeakerCatalog::MODE_CUSTOM,
        'visitor-voice-01',
    );

    $workflow = app(TranslationWorkflowStore::class)->find($result['id']);

    expect($workflow['speaker_reference_id'])->toBe('visitor-voice-01');
});

it('captures the system reference id when speaker mode is system', function () {
    Queue::fake();

    $result = app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'en',
        TranslationSpeakerCatalog::MODE_SYSTEM,
    );

    $workflow = app(TranslationWorkflowStore::class)->find($result['id']);

    expect($workflow['speaker_reference_id'])->toBe('test-fish-ref');
});

it('prefers language fish override over visitor custom default', function () {
    Queue::fake();

    config([
        'translation_languages.languages.ko.fish_reference_id' => 'lang-ko-voice',
    ]);

    $result = app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'ko',
        TranslationSpeakerCatalog::MODE_CUSTOM,
        'visitor-voice-01',
    );

    $workflow = app(TranslationWorkflowStore::class)->find($result['id']);

    expect($workflow['speaker_reference_id'])->toBe('lang-ko-voice');
});

it('keeps the turn speaker snapshot immutable after later submissions with different settings', function () {
    Queue::fake();

    $start = app(StartTranslationWorkflow::class);

    $first = $start('visitor-a', 'First', 'en', TranslationSpeakerCatalog::MODE_CUSTOM, 'voice-alpha');
    $second = $start('visitor-a', 'Second', 'en', TranslationSpeakerCatalog::MODE_CUSTOM, 'voice-beta');

    $store = app(TranslationWorkflowStore::class);

    expect($store->find($first['id'])['speaker_reference_id'])->toBe('voice-alpha')
        ->and($store->find($second['id'])['speaker_reference_id'])->toBe('voice-beta');
});

it('returns generic validation messages that do not echo reference ids', function () {
    try {
        app(StartTranslationWorkflow::class)(
            'visitor-a',
            'Hello',
            'ja',
            TranslationSpeakerCatalog::MODE_CUSTOM,
            null,
        );
    } catch (ValidationException $exception) {
        expect($exception->errors()['custom_reference_id'][0])
            ->toBe('A custom speaker reference ID is required.');

        return;
    }

    expect(false)->toBeTrue('Expected ValidationException was not thrown.');
});

it('rejects unsupported translation tone when starting a workflow', function () {
    Queue::fake();

    expect(fn () => app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'ja',
        TranslationSpeakerCatalog::MODE_SYSTEM,
        null,
        'casual',
    ))->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('captures the selected translation tone on the turn at submission', function () {
    Queue::fake();

    $result = app(StartTranslationWorkflow::class)(
        'visitor-a',
        'Hello',
        'en',
        TranslationSpeakerCatalog::MODE_SYSTEM,
        null,
        TranslationToneCatalog::CODE_BUSINESS,
    );

    $workflow = app(TranslationWorkflowStore::class)->find($result['id']);

    expect($workflow['translation_tone'])->toBe(TranslationToneCatalog::CODE_BUSINESS);
});

it('keeps the turn tone snapshot immutable after later submissions with different tone', function () {
    Queue::fake();

    $start = app(StartTranslationWorkflow::class);

    $first = $start(
        'visitor-a',
        'First',
        'en',
        TranslationSpeakerCatalog::MODE_SYSTEM,
        null,
        TranslationToneCatalog::CODE_BUSINESS,
    );
    $second = $start(
        'visitor-a',
        'Second',
        'en',
        TranslationSpeakerCatalog::MODE_SYSTEM,
        null,
        TranslationToneCatalog::CODE_ACADEMIC,
    );

    $store = app(TranslationWorkflowStore::class);

    expect($store->find($first['id'])['translation_tone'])->toBe(TranslationToneCatalog::CODE_BUSINESS)
        ->and($store->find($second['id'])['translation_tone'])->toBe(TranslationToneCatalog::CODE_ACADEMIC);
});

it('normalizes missing translation tone to normal when creating a turn via the store', function () {
    $store = app(TranslationWorkflowStore::class);

    $workflow = $store->create('visitor-a', 'Hello', 'ja', 'test-fish-ref', null);

    expect($workflow['translation_tone'])->toBe(TranslationToneCatalog::CODE_NORMAL);
});
