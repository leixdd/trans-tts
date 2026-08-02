<?php

namespace App\Services;

use App\Models\TranslationTurn;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TranslationWorkflowStore
{
    private const AUDIO_DIRECTORY = 'translation-audio';

    private const WORKER_LOGS_MAX_BYTES = 16_000;

    /**
     * @return array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }
     */
    public function create(
        string $visitorId,
        string $sourceText,
        ?string $targetLanguage = null,
        ?string $speakerReferenceId = null,
    ): array {
        if ($visitorId === '') {
            throw new RuntimeException('A visitor id is required to create a translation turn.');
        }

        /** @var TranslationLanguageCatalog $catalog */
        $catalog = app(TranslationLanguageCatalog::class);
        $language = $catalog->normalize($targetLanguage);

        $turn = TranslationTurn::query()->create([
            'visitor_id' => $visitorId,
            'status' => 'queued',
            'source_text' => $sourceText,
            'target_language' => $language,
            'speaker_reference_id' => $this->normalizeSpeakerReferenceId($speakerReferenceId),
            'translation' => null,
            'stream_debug' => null,
            'worker_logs' => null,
            'audio_path' => null,
            'error' => null,
            'expires_at' => now()->addDays($this->retentionDays()),
        ]);

        $this->enforceHistoryLimit($visitorId);

        return $this->toArray($turn);
    }

    /**
     * @return array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }|null
     */
    public function find(string $id): ?array
    {
        $turn = TranslationTurn::query()->find($id);

        if ($turn === null) {
            return null;
        }

        if ($this->isExpired($this->toArray($turn))) {
            $this->forget($id);

            return null;
        }

        return $this->toArray($turn);
    }

    /**
     * @param  array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }  $workflow
     */
    public function assertOwnedByVisitor(array $workflow, string $visitorId): void
    {
        if ($workflow['visitor_id'] !== $visitorId) {
            throw new AccessDeniedHttpException('This translation turn belongs to another visitor.');
        }
    }

    public function markStatus(string $id, string $status): void
    {
        $turn = $this->requireModel($id);
        $turn->status = $status;
        $turn->save();
    }

    public function setTranslation(string $id, string $translation): void
    {
        $turn = $this->requireModel($id);
        $turn->translation = $translation;
        $turn->save();
    }

    public function appendStreamDebug(string $id, string $accumulated, string $rawSseData): void
    {
        $turn = $this->requireModel($id);
        $existing = $turn->stream_debug ?? '';
        $line = 'delta: '.$rawSseData."\n".'accumulated: '.$accumulated."\n---\n";
        $turn->stream_debug = $existing.$line;
        $turn->translation = $accumulated;
        $turn->save();
    }

    public function appendWorkerLog(string $id, string $message): void
    {
        $turn = $this->requireModel($id);
        $existing = $turn->worker_logs ?? '';
        $line = '['.now()->format('H:i:s').'] '.$message."\n";
        $logs = $existing.$line;

        if (strlen($logs) > self::WORKER_LOGS_MAX_BYTES) {
            $logs = substr($logs, -self::WORKER_LOGS_MAX_BYTES);
        }

        $turn->worker_logs = $logs;
        $turn->save();
    }

    public function storeAudio(string $id, string $wavBytes): string
    {
        $turn = $this->requireModel($id);
        $path = self::AUDIO_DIRECTORY.'/'.$id.'.wav';
        $this->disk()->put($path, $wavBytes);
        $turn->audio_path = $path;
        $turn->save();

        return $path;
    }

    public function markFailed(string $id, string $error): void
    {
        $turn = $this->requireModel($id);
        $turn->status = 'failed';
        $turn->error = $error;
        $turn->save();
    }

    public function markCompleted(string $id): void
    {
        $turn = $this->requireModel($id);
        $turn->status = 'completed';
        $turn->error = null;
        $turn->save();
    }

    public function forget(string $id): void
    {
        $turn = TranslationTurn::query()->find($id);

        if ($turn !== null) {
            if (is_string($turn->audio_path) && $turn->audio_path !== '') {
                $this->disk()->delete($turn->audio_path);
            }

            $turn->delete();
        } else {
            $this->disk()->delete(self::AUDIO_DIRECTORY.'/'.$id.'.wav');
        }
    }

    public function cleanupExpired(): int
    {
        $removed = 0;

        TranslationTurn::query()
            ->where('expires_at', '<', now())
            ->orderBy('created_at')
            ->chunkById(100, function ($turns) use (&$removed): void {
                foreach ($turns as $turn) {
                    $this->forget($turn->id);
                    $removed++;
                }
            });

        foreach ($this->disk()->files(self::AUDIO_DIRECTORY) as $path) {
            $filename = basename($path);
            if (! str_ends_with($filename, '.wav')) {
                continue;
            }

            $id = basename($filename, '.wav');
            if ($this->find($id) === null) {
                $this->disk()->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    public function signedAudioUrl(string $id): ?string
    {
        $workflow = $this->find($id);

        if ($workflow === null || $workflow['status'] !== 'completed' || blank($workflow['audio_path'])) {
            return null;
        }

        return URL::temporarySignedRoute(
            'translations.audio',
            now()->addMinutes($this->signedUrlMinutes()),
            ['workflow' => $id],
        );
    }

    /**
     * @return array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }
     */
    public function requireOwned(string $id, string $visitorId): array
    {
        $workflow = $this->find($id);

        if ($workflow === null) {
            throw new NotFoundHttpException('Translation turn not found or expired.');
        }

        $this->assertOwnedByVisitor($workflow, $visitorId);

        return $workflow;
    }

    /**
     * Session-scoped polling payload for the UI (never includes audio_path or speaker_reference_id).
     *
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
     * }
     */
    public function publicStatus(string $id, string $visitorId): array
    {
        $workflow = $this->requireOwned($id, $visitorId);

        return $this->toPublicPayload($workflow);
    }

    /**
     * Ownership-checked progressive snapshot for the browser SSE relay.
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     translation: string|null,
     *     error: string|null,
     *     terminal: bool
     * }
     */
    public function streamSnapshot(string $id, string $visitorId): array
    {
        $workflow = $this->requireOwned($id, $visitorId);
        $terminal = in_array($workflow['status'], ['completed', 'failed'], true);

        return [
            'id' => $workflow['id'],
            'status' => $workflow['status'],
            'translation' => $workflow['translation'],
            'error' => $workflow['error'],
            'terminal' => $terminal,
        ];
    }

    /**
     * Latest turns for a visitor, oldest first (chat order).
     *
     * @return list<array{
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
    public function listForVisitor(string $visitorId): array
    {
        $limit = $this->historyLimit();

        $turns = TranslationTurn::query()
            ->where('visitor_id', $visitorId)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->sortBy('created_at')
            ->values();

        return array_values($turns
            ->map(fn (TranslationTurn $turn): array => $this->toPublicPayload($this->toArray($turn)))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    public function isExpired(array $workflow): bool
    {
        $expiresAt = $workflow['expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return true;
        }

        return now()->greaterThan($expiresAt);
    }

    private function enforceHistoryLimit(string $visitorId): void
    {
        $limit = $this->historyLimit();
        $ids = TranslationTurn::query()
            ->where('visitor_id', $visitorId)
            ->orderByDesc('created_at')
            ->skip($limit)
            ->take(1000)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->forget((string) $id);
        }
    }

    private function requireModel(string $id): TranslationTurn
    {
        $turn = TranslationTurn::query()->find($id);

        if ($turn === null || $turn->expires_at->isPast()) {
            if ($turn !== null) {
                $this->forget($id);
            }

            throw new RuntimeException('Translation turn not found or expired.');
        }

        return $turn;
    }

    /**
     * @param  array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }  $workflow
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
     * }
     */
    private function toPublicPayload(array $workflow): array
    {
        $inFlight = in_array($workflow['status'], ['queued', 'translating', 'synthesizing'], true);

        /** @var TranslationLanguageCatalog $catalog */
        $catalog = app(TranslationLanguageCatalog::class);
        $targetLanguage = $catalog->normalize($workflow['target_language']);

        // Intentionally omit speaker_reference_id — private operational field only.
        return [
            'id' => $workflow['id'],
            'status' => $workflow['status'],
            'source_text' => $workflow['source_text'],
            'target_language' => $targetLanguage,
            'target_language_label' => $catalog->label($targetLanguage),
            'translation' => $workflow['translation'],
            'stream_debug' => $workflow['stream_debug'],
            'worker_logs' => $workflow['worker_logs'],
            'audio_url' => $workflow['status'] === 'completed'
                ? $this->signedAudioUrl($workflow['id'])
                : null,
            'stream_url' => $inFlight
                ? route('translations.stream', ['workflow' => $workflow['id']])
                : null,
            'error' => $workflow['error'],
            'created_at' => $workflow['created_at'],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     visitor_id: string,
     *     status: string,
     *     source_text: string,
     *     target_language: string,
     *     speaker_reference_id: string|null,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_path: string|null,
     *     error: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string
     * }
     */
    private function toArray(TranslationTurn $turn): array
    {
        /** @var TranslationLanguageCatalog $catalog */
        $catalog = app(TranslationLanguageCatalog::class);

        return [
            'id' => $turn->id,
            'visitor_id' => $turn->visitor_id,
            'status' => $turn->status,
            'source_text' => $turn->source_text,
            'target_language' => $catalog->normalize($turn->target_language ?? null),
            'speaker_reference_id' => $this->normalizeSpeakerReferenceId($turn->speaker_reference_id),
            'translation' => $turn->translation,
            'stream_debug' => $turn->stream_debug,
            'worker_logs' => $turn->worker_logs,
            'audio_path' => $turn->audio_path,
            'error' => $turn->error,
            'created_at' => $turn->created_at?->toIso8601String() ?? '',
            'updated_at' => $turn->updated_at?->toIso8601String() ?? '',
            'expires_at' => $turn->expires_at->toIso8601String(),
        ];
    }

    private function normalizeSpeakerReferenceId(?string $speakerReferenceId): ?string
    {
        if (! is_string($speakerReferenceId)) {
            return null;
        }

        $trimmed = trim($speakerReferenceId);

        return $trimmed === '' ? null : $trimmed;
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('services.ai_provider.retention_days', 30));
    }

    private function historyLimit(): int
    {
        return max(1, (int) config('services.ai_provider.history_limit', 50));
    }

    private function signedUrlMinutes(): int
    {
        return max(1, (int) config('services.ai_provider.signed_url_minutes', 60));
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }
}
