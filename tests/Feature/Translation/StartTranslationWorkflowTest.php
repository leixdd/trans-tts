<?php

use App\Actions\StartTranslationWorkflow;
use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    configureNovitaForTests();
});

it('rejects empty text when starting a workflow', function () {
    expect(fn () => app(StartTranslationWorkflow::class)('session-a', ''))
        ->toThrow(ValidationException::class);
});

it('rejects text longer than 10000 characters when starting a workflow', function () {
    expect(fn () => app(StartTranslationWorkflow::class)('session-a', str_repeat('a', 10001)))
        ->toThrow(ValidationException::class);
});

it('returns queued status and dispatches the synthesis job on success', function () {
    Queue::fake();

    $result = app(StartTranslationWorkflow::class)('session-a', 'Hello world');

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
        ->and($workflow['source_text'])->toBe('Hello world')
        ->and($workflow['worker_logs'])->toContain('Workflow created')
        ->and($workflow['worker_logs'])->toContain('Job dispatched to queue');
});
