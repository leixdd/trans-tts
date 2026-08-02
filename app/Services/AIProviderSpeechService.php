<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AIProviderSpeechService
{
    public function __construct(
        private readonly TranslationLanguageCatalog $languages,
    ) {}

    /**
     * Synthesize translated text to WAV bytes via AIProvider Fish Audio S2 Pro.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     *
     * @param  string|null  $referenceId  Explicit Fish reference captured on the turn; null uses legacy language→global fallback
     *
     * @throws RuntimeException
     */
    public function synthesize(string $text, string $targetLanguage, ?string $referenceId = null): string
    {
        $language = $this->languages->normalize($targetLanguage);

        $apiKey = config('services.ai_provider.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('AIProvider API key is not configured.');
        }

        try {
            $resolvedReferenceId = $this->resolveReferenceId($language, $referenceId);
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        $endpoint = (string) config('services.ai_provider.tts_endpoint');
        $timeout = (int) config('services.ai_provider.timeout', 60);
        $userAgent = (string) config('services.ai_provider.user_agent', 'tts-app/1.0');

        if ($endpoint === '') {
            throw new RuntimeException('AIProvider TTS endpoint is not configured.');
        }

        if (trim($text) === '') {
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
                    'text' => $text,
                    'format' => 'wav',
                    'reference_id' => $resolvedReferenceId,
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

    /**
     * Prefer an explicit turn-captured reference; legacy null falls back via language catalog.
     *
     * @throws Throwable
     */
    private function resolveReferenceId(string $language, ?string $explicitReferenceId): string
    {
        if (is_string($explicitReferenceId) && $explicitReferenceId !== '') {
            return $explicitReferenceId;
        }

        return $this->languages->fishReferenceId($language);
    }
}
