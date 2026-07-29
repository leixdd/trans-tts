<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslationTurnStreamService
{
    public function __construct(
        private readonly TranslationWorkflowStore $store,
    ) {}

    /**
     * Stream idempotent turn snapshots for an owned translation turn.
     */
    public function stream(string $turnId, string $visitorId): StreamedResponse
    {
        // Authorize before holding an Octane worker on the long-lived connection.
        $this->store->streamSnapshot($turnId, $visitorId);

        $pollSeconds = $this->pollSeconds();
        $heartbeatSeconds = $this->heartbeatSeconds();
        $maxSeconds = $this->maxSeconds();

        return response()->stream(function () use (
            $turnId,
            $visitorId,
            $pollSeconds,
            $heartbeatSeconds,
            $maxSeconds,
        ): void {
            $startedAt = microtime(true);
            $lastFingerprint = null;
            $lastHeartbeatAt = 0.0;

            while (true) {
                if (connection_aborted()) {
                    return;
                }

                $elapsed = microtime(true) - $startedAt;

                if ($elapsed >= $maxSeconds) {
                    $this->emit('reconnect', [
                        'id' => $turnId,
                        'reason' => 'max_duration',
                    ]);

                    return;
                }

                $snapshot = $this->store->streamSnapshot($turnId, $visitorId);
                $fingerprint = $this->fingerprint($snapshot);

                if ($fingerprint !== $lastFingerprint) {
                    $event = $snapshot['terminal'] ? 'terminal' : 'snapshot';
                    $this->emit($event, $snapshot);
                    $lastFingerprint = $fingerprint;
                    $lastHeartbeatAt = microtime(true);

                    if ($snapshot['terminal']) {
                        return;
                    }
                } elseif ((microtime(true) - $lastHeartbeatAt) >= $heartbeatSeconds) {
                    $this->emit('heartbeat', [
                        'id' => $turnId,
                        'ts' => now()->toIso8601String(),
                    ]);
                    $lastHeartbeatAt = microtime(true);
                }

                usleep((int) round($pollSeconds * 1_000_000));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }

    /**
     * @param  array{
     *     id: string,
     *     status: string,
     *     translation: string|null,
     *     error: string|null,
     *     terminal: bool
     * }  $snapshot
     */
    private function fingerprint(array $snapshot): string
    {
        return implode("\0", [
            $snapshot['id'],
            $snapshot['status'],
            $snapshot['translation'] ?? '',
            $snapshot['error'] ?? '',
            $snapshot['terminal'] ? '1' : '0',
        ]);
    }

    private function pollSeconds(): float
    {
        return max(0.1, (float) config('services.novita.stream_poll_seconds', 0.5));
    }

    private function heartbeatSeconds(): float
    {
        return max(1.0, (float) config('services.novita.stream_heartbeat_seconds', 15));
    }

    private function maxSeconds(): float
    {
        return max(5.0, (float) config('services.novita.stream_max_seconds', 300));
    }
}
