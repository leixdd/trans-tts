<?php

use App\Livewire\TranslationWorkspace;
use App\Services\TranslationWorkflowStore;
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

it('does not submit again while a workflow is in flight', function () {
    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('workflowId', 'existing-workflow')
        ->set('status', 'translating')
        ->call('submit')
        ->assertSet('workflowId', 'existing-workflow')
        ->assertSet('status', 'translating');
});

it('updates status from pollStatus when the workflow completes', function () {
    $this->startSession();
    $sessionId = session()->getId();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($sessionId, 'Hello');
    $store->setTranslation($workflow['id'], '翻訳完了');
    $store->storeAudio($workflow['id'], fakeWavBytes());
    $store->markCompleted($workflow['id']);

    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Hello')
        ->set('workflowId', $workflow['id'])
        ->set('status', 'synthesizing')
        ->call('pollStatus')
        ->assertSet('status', 'completed')
        ->assertSet('translation', '翻訳完了')
        ->assertNotSet('audioUrl', null);
});

it('retains English input and exposes failure state for retry', function () {
    $this->startSession();
    $sessionId = session()->getId();

    $store = app(TranslationWorkflowStore::class);
    $workflow = $store->create($sessionId, 'Keep this text');
    $store->markFailed($workflow['id'], 'Translation failed. Please try again.');

    Livewire::test(TranslationWorkspace::class)
        ->set('text', 'Keep this text')
        ->set('workflowId', $workflow['id'])
        ->set('status', 'synthesizing')
        ->call('pollStatus')
        ->assertSet('status', 'failed')
        ->assertSet('error', 'Translation failed. Please try again.')
        ->assertSet('text', 'Keep this text');
});
