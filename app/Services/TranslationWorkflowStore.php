<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TranslationWorkflowStore
{
    private const CACHE_PREFIX = 'translation_workflow:';

    private const INDEX_KEY = 'translation_workflows:index';

    private const AUDIO_DIRECTORY = 'translation-audio';

    private const WORKER_LOGS_MAX_BYTES = 16_000;

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    public function create(string $sessionId, string $sourceText): array
    {
        if ($sessionId === '') {
            throw new RuntimeException('A session id is required to create a translation workflow.');
        }

        $now = now();
        $retentionMinutes = $this->retentionMinutes();
        $id = (string) Str::uuid();

        $workflow = [
            'id' => $id,
            'session_id' => $sessionId,
            'status' => 'queued',
            'source_text' => $sourceText,
            'translation' => null,
            'stream_debug' => null,
            'worker_logs' => null,
            'audio_path' => null,
            'error' => null,
            'created_at' => $now->toIso8601String(),
            'updated_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes($retentionMinutes)->toIso8601String(),
        ];

        $this->put($workflow);
        $this->rememberInIndex($id);

        return $workflow;
    }

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
        /** @var mixed $workflow */
        $workflow = $this->cache()->get($this->cacheKey($id));

        if (! is_array($workflow)) {
            return null;
        }

        if ($this->isExpired($workflow)) {
            $this->forget($id);

            return null;
        }

        return $this->normalize($workflow);
    }

    /**
     * @param  array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    public function assertOwnedBySession(array $workflow, string $sessionId): void
    {
        if ($workflow['session_id'] !== $sessionId) {
            throw new AccessDeniedHttpException('This translation workflow belongs to another session.');
        }
    }

    public function markStatus(string $id, string $status): void
    {
        $workflow = $this->require($id);
        $workflow['status'] = $status;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    public function setTranslation(string $id, string $translation): void
    {
        $workflow = $this->require($id);
        $workflow['translation'] = $translation;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    /**
     * Append a Novita SSE debug line and the accumulated streamed translation.
     */
    public function appendStreamDebug(string $id, string $accumulated, string $rawSseData): void
    {
        $workflow = $this->require($id);
        $existing = $workflow['stream_debug'] ?? '';

        $line = 'delta: '.$rawSseData."\n".'accumulated: '.$accumulated."\n---\n";
        $workflow['stream_debug'] = $existing.$line;
        $workflow['translation'] = $accumulated;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    /**
     * Append a timestamped worker/debug log line for UI diagnostics.
     */
    public function appendWorkerLog(string $id, string $message): void
    {
        $workflow = $this->require($id);
        $existing = $workflow['worker_logs'] ?? '';
        $line = '['.now()->format('H:i:s').'] '.$message."\n";
        $logs = $existing.$line;

        if (strlen($logs) > self::WORKER_LOGS_MAX_BYTES) {
            $logs = substr($logs, -self::WORKER_LOGS_MAX_BYTES);
        }

        $workflow['worker_logs'] = $logs;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    public function storeAudio(string $id, string $wavBytes): string
    {
        $workflow = $this->require($id);
        $path = self::AUDIO_DIRECTORY.'/'.$id.'.wav';

        $this->disk()->put($path, $wavBytes);

        $workflow['audio_path'] = $path;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);

        return $path;
    }

    public function markFailed(string $id, string $error): void
    {
        $workflow = $this->require($id);
        $workflow['status'] = 'failed';
        $workflow['error'] = $error;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    public function markCompleted(string $id): void
    {
        $workflow = $this->require($id);
        $workflow['status'] = 'completed';
        $workflow['error'] = null;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->put($workflow);
    }

    public function forget(string $id): void
    {
        $workflow = $this->cache()->get($this->cacheKey($id));

        if (is_array($workflow)) {
            $path = $workflow['audio_path'] ?? null;
            if (is_string($path) && $path !== '') {
                $this->disk()->delete($path);
            }
        } else {
            $this->disk()->delete(self::AUDIO_DIRECTORY.'/'.$id.'.wav');
        }

        $this->cache()->forget($this->cacheKey($id));
        $this->forgetFromIndex($id);
    }

    /**
     * Remove expired cache entries and orphaned private WAV files.
     */
    public function cleanupExpired(): int
    {
        $removed = 0;
        $ids = $this->indexedIds();

        foreach ($ids as $id) {
            /** @var mixed $workflow */
            $workflow = $this->cache()->get($this->cacheKey($id));

            if (! is_array($workflow) || $this->isExpired($workflow)) {
                $this->forget($id);
                $removed++;
            }
        }

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
            now()->addMinutes($this->retentionMinutes()),
            ['workflow' => $id],
        );
    }

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    public function requireOwned(string $id, string $sessionId): array
    {
        $workflow = $this->find($id);

        if ($workflow === null) {
            throw new NotFoundHttpException('Translation workflow not found or expired.');
        }

        $this->assertOwnedBySession($workflow, $sessionId);

        return $workflow;
    }

    /**
     * Session-scoped polling payload for the UI (never includes source_text or audio_path).
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     translation: string|null,
     *     stream_debug: string|null,
     *     worker_logs: string|null,
     *     audio_url: string|null,
     *     error: string|null
     * }
     */
    public function publicStatus(string $id, string $sessionId): array
    {
        $workflow = $this->requireOwned($id, $sessionId);

        return [
            'id' => $workflow['id'],
            'status' => $workflow['status'],
            'translation' => $workflow['translation'],
            'stream_debug' => $workflow['stream_debug'],
            'worker_logs' => $workflow['worker_logs'],
            'audio_url' => $workflow['status'] === 'completed'
                ? $this->signedAudioUrl($id)
                : null,
            'error' => $workflow['error'],
        ];
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

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    private function require(string $id): array
    {
        $workflow = $this->find($id);

        if ($workflow === null) {
            throw new RuntimeException('Translation workflow not found or expired.');
        }

        return $workflow;
    }

    /**
     * @param  array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    private function put(array $workflow): void
    {
        $this->cache()->put(
            $this->cacheKey($workflow['id']),
            $workflow,
            now()->addMinutes($this->retentionMinutes()),
        );
    }

    private function cacheKey(string $id): string
    {
        return self::CACHE_PREFIX.$id;
    }

    private function retentionMinutes(): int
    {
        $minutes = (int) config('services.novita.retention_minutes', 60);

        return max(1, $minutes);
    }

    private function cache(): CacheRepository
    {
        return Cache::store();
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }

    private function rememberInIndex(string $id): void
    {
        $ids = $this->indexedIds();
        $ids[] = $id;
        $this->cache()->put(self::INDEX_KEY, array_values(array_unique($ids)), now()->addDays(7));
    }

    private function forgetFromIndex(string $id): void
    {
        $ids = array_values(array_filter(
            $this->indexedIds(),
            static fn (string $indexedId): bool => $indexedId !== $id,
        ));

        $this->cache()->put(self::INDEX_KEY, $ids, now()->addDays(7));
    }

    /**
     * @return list<string>
     */
    private function indexedIds(): array
    {
        /** @var mixed $ids */
        $ids = $this->cache()->get(self::INDEX_KEY, []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array{
     *     id: string,
     *     session_id: string,
     *     status: string,
     *     source_text: string,
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
    private function normalize(array $workflow): array
    {
        return [
            'id' => (string) ($workflow['id'] ?? ''),
            'session_id' => (string) ($workflow['session_id'] ?? ''),
            'status' => (string) ($workflow['status'] ?? 'failed'),
            'source_text' => (string) ($workflow['source_text'] ?? ''),
            'translation' => isset($workflow['translation']) && is_string($workflow['translation'])
                ? $workflow['translation']
                : null,
            'stream_debug' => isset($workflow['stream_debug']) && is_string($workflow['stream_debug'])
                ? $workflow['stream_debug']
                : null,
            'worker_logs' => isset($workflow['worker_logs']) && is_string($workflow['worker_logs'])
                ? $workflow['worker_logs']
                : null,
            'audio_path' => isset($workflow['audio_path']) && is_string($workflow['audio_path'])
                ? $workflow['audio_path']
                : null,
            'error' => isset($workflow['error']) && is_string($workflow['error'])
                ? $workflow['error']
                : null,
            'created_at' => (string) ($workflow['created_at'] ?? ''),
            'updated_at' => (string) ($workflow['updated_at'] ?? ''),
            'expires_at' => (string) ($workflow['expires_at'] ?? ''),
        ];
    }
}
