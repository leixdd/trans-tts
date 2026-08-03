<?php

namespace App\Actions;

use App\Http\Requests\StartTranslationRequest;
use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\TranslationSpeakerCatalog;
use App\Services\TranslationToneCatalog;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StartTranslationWorkflow
{
    public function __construct(
        private readonly TranslationWorkflowStore $store,
        private readonly TranslationSpeakerCatalog $speakers,
    ) {}

    /**
     * Validate input, create a queued turn with immutable speaker/tone snapshots, and dispatch synthesis.
     *
     * @return array{id: string, status: string}
     *
     * @throws ValidationException
     */
    public function __invoke(
        string $visitorId,
        string $text,
        string $targetLanguage,
        string $speakerMode = TranslationSpeakerCatalog::MODE_SYSTEM,
        ?string $customReferenceId = null,
        string $translationTone = TranslationToneCatalog::CODE_NORMAL,
    ): array {
        $validated = Validator::make(
            [
                'visitor_id' => $visitorId,
                'text' => $text,
                'target_language' => $targetLanguage,
                'speaker_mode' => $speakerMode,
                'custom_reference_id' => $customReferenceId,
                'translation_tone' => $translationTone,
            ],
            [
                'visitor_id' => ['required', 'string'],
                'text' => StartTranslationRequest::textRules(),
                'target_language' => StartTranslationRequest::targetLanguageRules(),
                'speaker_mode' => StartTranslationRequest::speakerModeRules(),
                'custom_reference_id' => StartTranslationRequest::customReferenceIdRules('speaker_mode'),
                'translation_tone' => StartTranslationRequest::translationToneRules(),
            ],
            [
                'custom_reference_id.required_if' => 'A custom speaker reference ID is required.',
                'custom_reference_id.regex' => 'The custom speaker reference ID format is invalid.',
                'custom_reference_id.max' => 'The custom speaker reference ID is too long.',
            ],
        )->validate();

        $speakerReferenceId = $this->speakers->resolveEffectiveReferenceId(
            $validated['target_language'],
            $validated['speaker_mode'],
            $validated['custom_reference_id'] ?? null,
        );

        $workflow = $this->store->create(
            $validated['visitor_id'],
            $validated['text'],
            $validated['target_language'],
            $speakerReferenceId,
            $validated['translation_tone'],
        );

        $this->store->appendWorkerLog(
            $workflow['id'],
            'Turn created (status=queued)',
        );

        TranslateAndSynthesizeSpeech::dispatch($workflow['id']);

        $this->store->appendWorkerLog(
            $workflow['id'],
            'Job dispatched to queue — waiting for a worker (run `php artisan queue:work` or the Compose queue service)',
        );

        return [
            'id' => $workflow['id'],
            'status' => $workflow['status'],
        ];
    }
}
