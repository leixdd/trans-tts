<?php

namespace App\Jobs;

use App\Services\AIProviderSpeechService;
use App\Services\AIProviderTranslationService;
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
        AIProviderTranslationService $translator,
        AIProviderSpeechService $speech,
    ): void {
        $workflow = $store->find($this->workflowId);

        if ($workflow === null) {
            return;
        }

        if (in_array($workflow['status'], ['completed', 'failed'], true)) {
            $store->appendWorkerLog(
                $this->workflowId,
                'Skipped — workflow already '.$workflow['status'],
            );

            return;
        }

        $attempt = $this->attempts();
        $store->appendWorkerLog(
            $this->workflowId,
            "Worker picked up job (attempt {$attempt}/{$this->tries})",
        );

        try {
            $translation = $workflow['translation'];

            if ($translation === null || $translation === '') {
                $store->appendWorkerLog($this->workflowId, 'Starting AIProvider translation…');
                $store->markStatus($this->workflowId, 'translating');
                $translation = $translator->translate(
                    $workflow['source_text'],
                    $workflow['target_language'],
                    function (string $delta, string $accumulated, string $rawSseData) use ($store): void {
                        $store->appendStreamDebug($this->workflowId, $accumulated, $rawSseData);
                    },
                    $workflow['translation_tone'] ?? null,
                );
                $store->setTranslation($this->workflowId, $translation);
                $store->appendWorkerLog(
                    $this->workflowId,
                    'Translation complete ('.mb_strlen($translation).' chars)',
                );
            } else {
                $store->appendWorkerLog(
                    $this->workflowId,
                    'Reusing stored translation ('.mb_strlen($translation).' chars)',
                );
            }

            $fresh = $store->find($this->workflowId);
            if ($fresh === null) {
                return;
            }

            if (blank($fresh['audio_path'])) {
                $store->appendWorkerLog($this->workflowId, 'Starting Fish Audio speech synthesis…');
                $store->markStatus($this->workflowId, 'synthesizing');
                // Always synthesize the translated text — never the source text.
                // Pass the immutable turn-captured speaker; null keeps legacy language→global fallback.
                $speakerReferenceId = $fresh['speaker_reference_id'] ?? null;
                $audio = $speech->synthesize(
                    $translation,
                    $fresh['target_language'],
                    is_string($speakerReferenceId) ? $speakerReferenceId : null,
                );
                $store->storeAudio($this->workflowId, $audio);
                $store->appendWorkerLog(
                    $this->workflowId,
                    'Audio stored ('.strlen($audio).' bytes)',
                );
            } else {
                $store->appendWorkerLog($this->workflowId, 'Reusing stored audio');
            }

            $store->markCompleted($this->workflowId);
            $store->appendWorkerLog($this->workflowId, 'Workflow completed');
        } catch (Throwable $exception) {
            // Do not mark failed here — retries must remain possible until tries are exhausted.
            $store->appendWorkerLog(
                $this->workflowId,
                'Attempt failed: '.$this->safeExceptionSummary($exception)
                    .' — will retry if attempts remain',
            );

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

        $message = $this->userFacingMessage($exception);

        $store->appendWorkerLog(
            $this->workflowId,
            'Job failed permanently: '.$message,
        );

        $store->markFailed(
            $this->workflowId,
            $message,
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

    private function safeExceptionSummary(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if ($message === '') {
            return $exception::class;
        }

        // Keep logs useful without dumping secrets, reference IDs, or huge payloads.
        $summary = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $summary = preg_replace('/sk-[A-Za-z0-9_-]+/', '[redacted-key]', $summary) ?? $summary;
        $summary = preg_replace('/reference[_ ]?id["\']?\s*[:=]\s*["\']?[A-Za-z0-9_-]+/i', 'reference_id=[redacted]', $summary) ?? $summary;

        return mb_strlen($summary) > 200
            ? mb_substr($summary, 0, 200).'…'
            : $summary;
    }
}
