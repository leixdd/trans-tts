<?php

namespace App\Livewire;

use App\Actions\StartTranslationWorkflow;
use App\Http\Requests\StartTranslationRequest;
use App\Services\AnonymousVisitor;
use App\Services\TranslationLanguageCatalog;
use App\Services\TranslationSpeakerCatalog;
use App\Services\TranslationToneCatalog;
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

    private const SESSION_TRANSLATION_TONE_KEY = 'translation_tone';

    private const SESSION_SPEAKER_MODE_KEY = 'translation_speaker_mode';

    private const SESSION_CUSTOM_REFERENCE_ID_KEY = 'translation_speaker_custom_reference_id';

    public string $text = '';

    public string $targetLanguage = 'ja';

    public string $translationTone = TranslationToneCatalog::CODE_NORMAL;

    public string $speakerMode = TranslationSpeakerCatalog::MODE_SYSTEM;

    public ?string $customReferenceId = null;

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
        TranslationToneCatalog $tones,
        TranslationSpeakerCatalog $speakers,
    ): void {
        $stored = session(self::SESSION_TARGET_LANGUAGE_KEY);
        $this->targetLanguage = $languages->normalize(is_string($stored) ? $stored : null);

        $storedTone = session(self::SESSION_TRANSLATION_TONE_KEY);
        $this->translationTone = $tones->normalize(is_string($storedTone) ? $storedTone : null);

        $storedMode = session(self::SESSION_SPEAKER_MODE_KEY);
        $storedCustom = session(self::SESSION_CUSTOM_REFERENCE_ID_KEY);
        $selection = $speakers->normalizeSelection(
            is_string($storedMode) ? $storedMode : null,
            is_string($storedCustom) ? $storedCustom : null,
        );
        $this->speakerMode = $selection['mode'];
        $this->customReferenceId = $selection['custom_reference_id'];

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

    public function updatedTranslationTone(TranslationToneCatalog $tones): void
    {
        if (! $tones->isSupported($this->translationTone)) {
            return;
        }

        session([self::SESSION_TRANSLATION_TONE_KEY => $this->translationTone]);
    }

    public function updatedSpeakerMode(TranslationSpeakerCatalog $speakers): void
    {
        if (! $speakers->isSupportedMode($this->speakerMode)) {
            return;
        }

        $this->persistSpeakerSelection();
    }

    public function updatedCustomReferenceId(): void
    {
        $this->normalizeCustomReferenceIdProperty();
        $this->persistSpeakerSelection();
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

    /**
     * @return list<array{code: string, label: string}>
     */
    #[Computed]
    public function toneOptions(): array
    {
        return app(TranslationToneCatalog::class)->options();
    }

    /**
     * @return list<array{mode: string, label: string}>
     */
    #[Computed]
    public function speakerOptions(): array
    {
        return app(TranslationSpeakerCatalog::class)->options();
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
        $this->normalizeCustomReferenceIdProperty();

        $this->validate(
            [
                'text' => StartTranslationRequest::textRules(),
                'targetLanguage' => StartTranslationRequest::targetLanguageRules(),
                'translationTone' => StartTranslationRequest::translationToneRules(),
                'speakerMode' => StartTranslationRequest::speakerModeRules(),
                'customReferenceId' => StartTranslationRequest::customReferenceIdRules('speakerMode'),
            ],
            [
                'customReferenceId.required_if' => 'A custom speaker reference ID is required.',
                'customReferenceId.regex' => 'The custom speaker reference ID format is invalid.',
                'customReferenceId.max' => 'The custom speaker reference ID is too long.',
            ],
        );

        session([
            self::SESSION_TARGET_LANGUAGE_KEY => $this->targetLanguage,
            self::SESSION_TRANSLATION_TONE_KEY => $this->translationTone,
            self::SESSION_SPEAKER_MODE_KEY => $this->speakerMode,
            self::SESSION_CUSTOM_REFERENCE_ID_KEY => $this->customReferenceId,
        ]);

        $visitorId = $visitors->idFrom(request());
        $source = $this->text;
        $started = $start(
            $visitorId,
            $source,
            $this->targetLanguage,
            $this->speakerMode,
            $this->customReferenceId,
            $this->translationTone,
        );

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

    private function normalizeCustomReferenceIdProperty(): void
    {
        if (! is_string($this->customReferenceId)) {
            $this->customReferenceId = null;

            return;
        }

        $trimmed = trim($this->customReferenceId);
        $this->customReferenceId = $trimmed === '' ? null : $trimmed;
    }

    private function persistSpeakerSelection(): void
    {
        session([
            self::SESSION_SPEAKER_MODE_KEY => $this->speakerMode,
            self::SESSION_CUSTOM_REFERENCE_ID_KEY => $this->customReferenceId,
        ]);
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
