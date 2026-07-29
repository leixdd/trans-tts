<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AIProviderSpeechService
{
    /**
     * Synthesize Japanese text to WAV bytes via AIProvider Fish Audio S2 Pro.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     *
     * @throws RuntimeException
     */
    public function synthesize(string $japaneseText): string
    {
        $apiKey = config('services.ai_provider.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('AIProvider API key is not configured.');
        }

        $referenceId = config('services.ai_provider.fish_reference_id');
        if (! is_string($referenceId) || $referenceId === '') {
            throw new RuntimeException('AIProvider Fish Audio reference_id is not configured.');
        }

        $endpoint = (string) config('services.ai_provider.tts_endpoint');
        $timeout = (int) config('services.ai_provider.timeout', 60);
        $userAgent = (string) config('services.ai_provider.user_agent', 'tts-app/1.0');

        if ($endpoint === '') {
            throw new RuntimeException('AIProvider TTS endpoint is not configured.');
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
            throw new RuntimeException('AIProvider speech request failed to connect.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'AIProvider speech request failed with HTTP '.$response->status().'.',
            );
        }

        $audio = $response->body();

        if ($audio === '' || ! str_starts_with($audio, 'RIFF')) {
            throw new RuntimeException('AIProvider speech returned empty or invalid WAV audio.');
        }

        return $audio;
    }
}
