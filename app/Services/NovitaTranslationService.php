<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NovitaTranslationService
{
    private const SYSTEM_PROMPT = 'You are a translator. Translate the user\'s English text into Japanese only. Return only the Japanese translation text with no explanations, notes, labels, or quotation marks.';

    /**
     * Translate English source text to Japanese via Novita chat completions.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     *
     * @throws RuntimeException
     */
    public function translate(string $englishText): string
    {
        $apiKey = config('services.novita.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Novita API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.novita.chat_base_url'), '/');
        $model = (string) config('services.novita.translation_model');
        $timeout = (int) config('services.novita.timeout', 60);
        $userAgent = (string) config('services.novita.user_agent', 'tts-app/1.0');

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('Novita translation endpoint or model is not configured.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'User-Agent' => $userAgent,
                ])
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => self::SYSTEM_PROMPT,
                        ],
                        [
                            'role' => 'user',
                            'content' => $englishText,
                        ],
                    ],
                    'temperature' => 0.2,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Novita translation request failed to connect.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Novita translation request failed with HTTP '.$response->status().'.',
            );
        }

        /** @var mixed $content */
        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content)) {
            throw new RuntimeException('Novita translation returned a malformed response.');
        }

        $translation = trim($content);

        if ($translation === '') {
            throw new RuntimeException('Novita translation returned an empty response.');
        }

        return $translation;
    }
}
