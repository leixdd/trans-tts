<?php

namespace App\Livewire;

use App\Actions\StartTranslationWorkflow;
use App\Http\Requests\StartTranslationRequest;
use App\Services\TranslationWorkflowStore;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

#[Layout('layouts::app')]
#[Title('Translate & Speak')]
class TranslationWorkspace extends Component
{
    public string $text = '';

    public ?string $workflowId = null;

    public ?string $status = null;

    public ?string $translation = null;

    public ?string $streamDebug = null;

    public ?string $workerLogs = null;

    public ?string $audioUrl = null;

    public ?string $error = null;

    /**
     * @var list<string>
     */
    private const IN_FLIGHT_STATUSES = ['queued', 'translating', 'synthesizing'];

    #[Computed]
    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT_STATUSES, true);
    }

    #[Computed]
    public function latestWorkerLog(): ?string
    {
        if ($this->workerLogs === null || trim($this->workerLogs) === '') {
            return null;
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($this->workerLogs)) ?: [];
        $last = $lines === [] ? null : $lines[array_key_last($lines)];

        return filled($last) ? $last : null;
    }

    public function submit(StartTranslationWorkflow $start, TranslationWorkflowStore $store): void
    {
        if ($this->isInFlight()) {
            return;
        }

        $this->validate([
            'text' => StartTranslationRequest::textRules(),
        ]);

        $this->clearResultState();

        $started = $start(session()->getId(), $this->text);

        $this->workflowId = $started['id'];
        $this->status = $started['status'];

        try {
            $payload = $store->publicStatus($this->workflowId, session()->getId());
            $this->workerLogs = $payload['worker_logs'];
            $this->streamDebug = $payload['stream_debug'];
        } catch (Throwable) {
            // Polling will refresh debug state shortly.
        }
    }

    public function pollStatus(TranslationWorkflowStore $store): void
    {
        if ($this->workflowId === null || ! $this->isInFlight()) {
            return;
        }

        try {
            $payload = $store->publicStatus($this->workflowId, session()->getId());
        } catch (HttpExceptionInterface) {
            $this->status = 'failed';
            $this->error = 'This translation is no longer available. Please try again.';
            $this->audioUrl = null;

            return;
        } catch (Throwable) {
            $this->status = 'failed';
            $this->error = 'Unable to check translation progress. Please try again.';
            $this->audioUrl = null;

            return;
        }

        $this->status = $payload['status'];
        $this->translation = $payload['translation'];
        $this->streamDebug = $payload['stream_debug'];
        $this->workerLogs = $payload['worker_logs'];
        $this->audioUrl = $payload['audio_url'];
        $this->error = $payload['error'];
    }

    public function render(): View
    {
        return view('livewire.translation-workspace');
    }

    private function clearResultState(): void
    {
        $this->workflowId = null;
        $this->status = null;
        $this->translation = null;
        $this->streamDebug = null;
        $this->workerLogs = null;
        $this->audioUrl = null;
        $this->error = null;
    }
}
