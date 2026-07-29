<?php

use App\Actions\StartTranslationWorkflow;
use App\Jobs\TranslateAndSynthesizeSpeech;
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
