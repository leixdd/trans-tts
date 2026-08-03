<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

class TranslationToneCatalog
{
    public const CODE_NORMAL = 'normal';

    public const CODE_BUSINESS = 'business';

    public const CODE_ACADEMIC = 'academic';

    public function defaultCode(): string
    {
        $default = (string) config('translation_tones.default', self::CODE_NORMAL);

        return $this->isSupported($default) ? $default : self::CODE_NORMAL;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        /** @var array<string, mixed> $tones */
        $tones = config('translation_tones.tones', []);

        return array_keys($tones);
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

        return is_string($label) && $label !== '' ? $label : ucfirst($code);
    }

    /**
     * Style directive appended to the language translation prompt.
     *
     * @throws InvalidArgumentException
     */
    public function promptDirective(string $code): string
    {
        $entry = $this->entry($code);
        $directive = $entry['prompt_directive'] ?? null;

        if (! is_string($directive) || trim($directive) === '') {
            throw new InvalidArgumentException("Translation tone prompt directive is not configured for [{$code}].");
        }

        return $directive;
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
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        return ['required', 'string', Rule::in($this->codes())];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $code): array
    {
        if (! $this->isSupported($code)) {
            throw new InvalidArgumentException("Unsupported translation tone [{$code}].");
        }

        /** @var array<string, mixed> $entry */
        $entry = config("translation_tones.tones.{$code}", []);

        return $entry;
    }
}
