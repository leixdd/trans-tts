<?php

namespace App\Livewire;

use App\Actions\StartTranslationWorkflow;
use App\Http\Requests\StartTranslationRequest;
use App\Services\AnonymousVisitor;
use App\Services\TranslationLanguageCatalog;
use App\Services\TranslationWorkflowStore;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Translate & Speak')]
class TranslationWorkspace extends Component
{
    private const SESSION_TARGET_LANGUAGE_KEY = 'translation_target_language';

    public string $text = '';

    public string $targetLanguage = 'ja';

    public bool $showDebugLogs = false;

    public ?string $debugTurnId = null;

    /**
     * @var list<array{
     *     id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     target_language_label: string,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_url: string|null,
     *     stream_url: string|null,
     *     error: string|null,
     *     created_at: string
     * }>
     */
    public array $turns = [];

    /**
     * @var list<string>
     */
    private const IN_FLIGHT_STATUSES = ['queued', 'translating', 'synthesizing'];

    public function mount(
        AnonymousVisitor $visitors,
        TranslationWorkflowStore $store,
        TranslationLanguageCatalog $languages,
    ): void {
        $stored = session(self::SESSION_TARGET_LANGUAGE_KEY);
        $this->targetLanguage = $languages->normalize(is_string($stored) ? $stored : null);

        $visitorId = $visitors->idFrom(request());
        $this->refreshTurns($store, $visitorId);
    }

    public function updatedTargetLanguage(TranslationLanguageCatalog $languages): void
    {
        if (! $languages->isSupported($this->targetLanguage)) {
            return;
        }

        session([self::SESSION_TARGET_LANGUAGE_KEY => $this->targetLanguage]);
    }

    public function toggleDebugLogs(): void
    {
        if (! $this->debugToolbarEnabled()) {
            return;
        }

        $this->showDebugLogs = ! $this->showDebugLogs;
    }

    public function selectDebugTurn(string $turnId): void
    {
        if (! $this->debugToolbarEnabled()) {
            return;
        }

        $this->debugTurnId = $turnId;
        $this->showDebugLogs = true;
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    #[Computed]
    public function languageOptions(): array
    {
        return app(TranslationLanguageCatalog::class)->options();
    }

    #[Computed]
    public function debugToolbarEnabled(): bool
    {
        return (bool) config('translation.debug_toolbar_enabled', true);
    }

    #[Computed]
    public function hasInFlightTurns(): bool
    {
        foreach ($this->turns as $turn) {
            if (in_array($turn['status'], self::IN_FLIGHT_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     target_language_label: string,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_url: string|null,
     *     stream_url: string|null,
     *     error: string|null,
     *     created_at: string
     * }|null
     */
    #[Computed]
    public function debugTurn(): ?array
    {
        if ($this->debugTurnId === null) {
            return $this->turns === [] ? null : $this->turns[array_key_last($this->turns)];
        }

        foreach ($this->turns as $turn) {
            if ($turn['id'] === $this->debugTurnId) {
                return $turn;
            }
        }

        return $this->turns === [] ? null : $this->turns[array_key_last($this->turns)];
    }

    public function submit(
        StartTranslationWorkflow $start,
        TranslationWorkflowStore $store,
        AnonymousVisitor $visitors,
        TranslationLanguageCatalog $languages,
    ): void {
        $this->validate([
            'text' => StartTranslationRequest::textRules(),
            'targetLanguage' => StartTranslationRequest::targetLanguageRules(),
        ]);

        session([self::SESSION_TARGET_LANGUAGE_KEY => $this->targetLanguage]);

        $visitorId = $visitors->idFrom(request());
        $source = $this->text;
        $started = $start($visitorId, $source, $this->targetLanguage);

        $this->text = '';
        $this->debugTurnId = $started['id'];
        $this->refreshTurns($store, $visitorId);
    }

    public function pollStatus(TranslationWorkflowStore $store, AnonymousVisitor $visitors): void
    {
        if (! $this->hasInFlightTurns()) {
            return;
        }

        $this->refreshTurns($store, $visitors->idFrom(request()));
    }

    public function render(): View
    {
        return view('livewire.translation-workspace');
    }

    private function refreshTurns(TranslationWorkflowStore $store, string $visitorId): void
    {
        $previous = [];
        foreach ($this->turns as $turn) {
            $previous[$turn['id']] = $turn['status'];
        }

        $this->turns = $store->listForVisitor($visitorId);

        $order = array_map(static fn (array $turn): string => $turn['id'], $this->turns);
        $this->dispatch('translation-playback-sync', order: $order);

        foreach ($this->turns as $turn) {
            $prior = $previous[$turn['id']] ?? null;

            // Only notify the FIFO player for in-session transitions, never restored history.
            if ($prior === null) {
                continue;
            }

            if ($turn['status'] === 'failed' && $prior !== 'failed') {
                $this->dispatch('translation-playback-failed', id: $turn['id']);
            }

            if (
                $turn['status'] === 'completed'
                && is_string($turn['audio_url'])
                && $turn['audio_url'] !== ''
                && $prior !== 'completed'
            ) {
                $this->dispatch('translation-audio-ready', id: $turn['id'], url: $turn['audio_url']);
            }
        }
    }
}
