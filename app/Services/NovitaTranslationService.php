<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NovitaTranslationService
{
    private const SYSTEM_PROMPT = 'You are a translator. Translate the user\'s English text into Japanese only. Return only the Japanese translation text with no explanations, notes, labels, or quotation marks.';

    /**
     * Translate English source text to Japanese via Novita streaming chat completions.
     *
     * Stateless and Octane-safe: no request-specific mutable properties.
     *
     * @param  (callable(string $delta, string $accumulated, string $rawSseData): void)|null  $onChunk
     *
     * @throws RuntimeException
     */
    public function translate(string $englishText, ?callable $onChunk = null): string
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
                ->accept('text/event-stream')
                ->asJson()
                ->timeout($timeout)
                ->withOptions(['stream' => true])
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
                    'stream' => true,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Novita translation request failed to connect.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Novita translation request failed with HTTP '.$response->status().'.',
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
                    throw new RuntimeException('Novita translation returned a malformed stream chunk.');
                }

                if (! is_array($decoded)) {
                    throw new RuntimeException('Novita translation returned a malformed stream chunk.');
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
            throw new RuntimeException('Novita translation returned an empty response.');
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
