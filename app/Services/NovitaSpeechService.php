<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NovitaSpeechService
{
    /**
     * Synthesize Japanese text to WAV bytes via Novita Fish Audio S2 Pro.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     *
     * @throws RuntimeException
     */
    public function synthesize(string $japaneseText): string
    {
        $apiKey = config('services.novita.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Novita API key is not configured.');
        }

        $referenceId = config('services.novita.fish_reference_id');
        if (! is_string($referenceId) || $referenceId === '') {
            throw new RuntimeException('Novita Fish Audio reference_id is not configured.');
        }

        $endpoint = (string) config('services.novita.tts_endpoint');
        $timeout = (int) config('services.novita.timeout', 60);
        $userAgent = (string) config('services.novita.user_agent', 'tts-app/1.0');

        if ($endpoint === '') {
            throw new RuntimeException('Novita TTS endpoint is not configured.');
        }

        if (trim($japaneseText) === '') {
            throw new RuntimeException('Cannot synthesize empty translation text.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    // Cloudflare blocks the default PHP/Guzzle User-Agent.
                    'User-Agent' => $userAgent,
                ])
                ->accept('audio/wav, application/octet-stream')
                ->asJson()
                ->timeout($timeout)
                ->post($endpoint, [
                    'text' => $japaneseText,
                    'format' => 'wav',
                    'reference_id' => $referenceId,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Novita speech request failed to connect.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Novita speech request failed with HTTP '.$response->status().'.',
            );
        }

        $audio = $response->body();

        if ($audio === '' || ! str_starts_with($audio, 'RIFF')) {
            throw new RuntimeException('Novita speech returned empty or invalid WAV audio.');
        }

        return $audio;
    }
}
