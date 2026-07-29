<?php

namespace App\Jobs;

use App\Services\NovitaSpeechService;
use App\Services\NovitaTranslationService;
use App\Services\TranslationWorkflowStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslateAndSynthesizeSpeech implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 3600;

    public function __construct(
        public string $workflowId,
    ) {}

    public function uniqueId(): string
    {
        return $this->workflowId;
    }

    public function handle(
        TranslationWorkflowStore $store,
        NovitaTranslationService $translator,
        NovitaSpeechService $speech,
    ): void {
        $workflow = $store->find($this->workflowId);

        if ($workflow === null) {
            return;
        }

        if (in_array($workflow['status'], ['completed', 'failed'], true)) {
            return;
        }

        try {
            $translation = $workflow['translation'];

            if ($translation === null || $translation === '') {
                $store->markStatus($this->workflowId, 'translating');
                $translation = $translator->translate($workflow['source_text']);
                $store->setTranslation($this->workflowId, $translation);
            }

            $fresh = $store->find($this->workflowId);
            if ($fresh === null) {
                return;
            }

            if (blank($fresh['audio_path'])) {
                $store->markStatus($this->workflowId, 'synthesizing');
                // Always synthesize the Japanese translation — never the English source.
                $audio = $speech->synthesize($translation);
                $store->storeAudio($this->workflowId, $audio);
            }

            $store->markCompleted($this->workflowId);
        } catch (Throwable $exception) {
            // Do not mark failed here — retries must remain possible until tries are exhausted.
            Log::warning('Translation workflow attempt failed.', [
                'workflow_id' => $this->workflowId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        /** @var TranslationWorkflowStore $store */
        $store = app(TranslationWorkflowStore::class);
        $workflow = $store->find($this->workflowId);

        if ($workflow === null || $workflow['status'] === 'failed') {
            return;
        }

        $store->markFailed(
            $this->workflowId,
            $this->userFacingMessage($exception),
        );
    }

    private function userFacingMessage(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?? '';

        if (str_contains($message, 'not configured')) {
            return 'Translation service is not configured. Please try again later.';
        }

        if (str_contains(strtolower($message), 'speech') || str_contains(strtolower($message), 'wav')) {
            return 'Speech synthesis failed. Please try again.';
        }

        return 'Translation failed. Please try again.';
    }
}
