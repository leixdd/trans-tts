<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AIProviderTranslationService
{
    public function __construct(
        private readonly TranslationLanguageCatalog $languages,
        private readonly TranslationToneCatalog $tones,
    ) {}

    /**
     * Translate source text into the allow-listed target language via AIProvider streaming chat.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     * System prompt = language instruction + tone directive; response must remain translation-only.
     *
     * @param  (callable(string $delta, string $accumulated, string $rawSseData): void)|null  $onChunk
     *
     * @throws RuntimeException
     */
    public function translate(
        string $sourceText,
        string $targetLanguage,
        ?callable $onChunk = null,
        ?string $tone = null,
    ): string {
        $language = $this->languages->normalize($targetLanguage);
        $toneCode = $this->tones->normalize($tone);

        try {
            $systemPrompt = trim(
                $this->languages->translationPrompt($language)
                .' '.$this->tones->promptDirective($toneCode)
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        $apiKey = config('services.ai_provider.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('AIProvider API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.ai_provider.chat_base_url'), '/');
        $model = (string) config('services.ai_provider.translation_model');
        $timeout = (int) config('services.ai_provider.timeout', 60);
        $userAgent = (string) config('services.ai_provider.user_agent', 'tts-app/1.0');

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('AIProvider translation endpoint or model is not configured.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'User-Agent' => $userAgent,
                ])
                ->accept('text/event-stream')
                ->asJson()
                ->timeout($timeout)
                ->withOptions(['stream' => true])
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $sourceText,
                        ],
                    ],
                    'temperature' => 0.2,
                    'stream' => true,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('AIProvider translation request failed to connect.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'AIProvider translation request failed with HTTP '.$response->status().'.',
            );
        }

        return $this->consumeStream($response, $onChunk);
    }

    /**
     * @param  (callable(string $delta, string $accumulated, string $rawSseData): void)|null  $onChunk
     *
     * @throws RuntimeException
     */
    private function consumeStream(Response $response, ?callable $onChunk): string
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $translation = '';

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($lineEnding = $this->nextLineEnding($buffer)) !== null) {
                [$line, $buffer] = $this->shiftLine($buffer, $lineEnding);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '' || $data === '[DONE]') {
                    if ($data === '[DONE]') {
                        break 2;
                    }

                    continue;
                }

                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new RuntimeException('AIProvider translation returned a malformed stream chunk.');
                }

                if (! is_array($decoded)) {
                    throw new RuntimeException('AIProvider translation returned a malformed stream chunk.');
                }

                /** @var mixed $delta */
                $delta = data_get($decoded, 'choices.0.delta.content');

                if (! is_string($delta) || $delta === '') {
                    continue;
                }

                $translation .= $delta;

                if ($onChunk !== null) {
                    $onChunk($delta, $translation, $data);
                }
            }
        }

        $translation = trim($translation);

        if ($translation === '') {
            throw new RuntimeException('AIProvider translation returned an empty response.');
        }

        return $translation;
    }

    private function nextLineEnding(string $buffer): ?string
    {
        $lf = strpos($buffer, "\n");
        $crlf = strpos($buffer, "\r\n");

        if ($lf === false && $crlf === false) {
            return null;
        }

        if ($crlf !== false && ($lf === false || $crlf <= $lf)) {
            return "\r\n";
        }

        return "\n";
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function shiftLine(string $buffer, string $ending): array
    {
        $pos = strpos($buffer, $ending);

        if ($pos === false) {
            return [trim($buffer), ''];
        }

        $line = trim(substr($buffer, 0, $pos));

        return [$line, substr($buffer, $pos + strlen($ending))];
    }
}
