<?php

namespace App\Actions;

use App\Http\Requests\StartTranslationRequest;
use App\Jobs\TranslateAndSynthesizeSpeech;
use App\Services\TranslationWorkflowStore;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StartTranslationWorkflow
{
    public function __construct(
        private readonly TranslationWorkflowStore $store,
    ) {}

    /**
     * Validate input, create a queued workflow, and dispatch synthesis.
     *
     * @return array{id: string, status: string}
     *
     * @throws ValidationException
     */
    public function __invoke(string $sessionId, string $text): array
    {
        $validated = Validator::make(
            [
                'session_id' => $sessionId,
                'text' => $text,
            ],
            [
                'session_id' => ['required', 'string'],
                'text' => StartTranslationRequest::textRules(),
            ],
        )->validate();

        $workflow = $this->store->create(
            $validated['session_id'],
            $validated['text'],
        );

        TranslateAndSynthesizeSpeech::dispatch($workflow['id']);

        return [
            'id' => $workflow['id'],
            'status' => $workflow['status'],
        ];
    }
}
