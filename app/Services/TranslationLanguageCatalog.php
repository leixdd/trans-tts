<?php

namespace App\Services;

use InvalidArgumentException;

class TranslationLanguageCatalog
{
    public function defaultCode(): string
    {
        $default = (string) config('translation_languages.default', 'ja');

        return $this->isSupported($default) ? $default : 'ja';
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        /** @var array<string, mixed> $languages */
        $languages = config('translation_languages.languages', []);

        return array_keys($languages);
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->codes() as $code) {
            $options[] = [
                'code' => $code,
                'label' => $this->label($code),
            ];
        }

        return $options;
    }

    public function isSupported(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function label(string $code): string
    {
        $entry = $this->entry($code);

        $label = $entry['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : strtoupper($code);
    }

    public function translationPrompt(string $code): string
    {
        $entry = $this->entry($code);
        $prompt = $entry['translation_prompt'] ?? null;

        if (! is_string($prompt) || trim($prompt) === '') {
            throw new InvalidArgumentException("Translation prompt is not configured for language [{$code}].");
        }

        return $prompt;
    }

    /**
     * Resolve Fish Audio reference id for the language, falling back to the global default.
     */
    public function fishReferenceId(string $code): string
    {
        $entry = $this->entry($code);
        $override = $entry['fish_reference_id'] ?? null;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $fallback = config('services.ai_provider.fish_reference_id');

        if (! is_string($fallback) || $fallback === '') {
            throw new InvalidArgumentException('AIProvider Fish Audio reference_id is not configured.');
        }

        return $fallback;
    }

    /**
     * Normalize an unknown/missing code to the default (for legacy turns).
     */
    public function normalize(?string $code): string
    {
        if (is_string($code) && $this->isSupported($code)) {
            return $code;
        }

        return $this->defaultCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $code): array
    {
        if (! $this->isSupported($code)) {
            throw new InvalidArgumentException("Unsupported target language [{$code}].");
        }

        /** @var array<string, mixed> $entry */
        $entry = config("translation_languages.languages.{$code}", []);

        return $entry;
    }
}
