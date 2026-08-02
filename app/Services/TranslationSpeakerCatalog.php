<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

class TranslationSpeakerCatalog
{
    public const MODE_SYSTEM = 'system';

    public const MODE_CUSTOM = 'custom';

    public function defaultMode(): string
    {
        $default = (string) config('translation_speakers.default_mode', self::MODE_SYSTEM);

        return $this->isSupportedMode($default) ? $default : self::MODE_SYSTEM;
    }

    /**
     * @return list<string>
     */
    public function modes(): array
    {
        /** @var array<string, mixed> $modes */
        $modes = config('translation_speakers.modes', []);

        return array_keys($modes);
    }

    /**
     * @return list<array{mode: string, label: string}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->modes() as $mode) {
            $options[] = [
                'mode' => $mode,
                'label' => $this->label($mode),
            ];
        }

        return $options;
    }

    public function isSupportedMode(string $mode): bool
    {
        return in_array($mode, $this->modes(), true);
    }

    public function label(string $mode): string
    {
        /** @var array<string, mixed> $entry */
        $entry = config("translation_speakers.modes.{$mode}", []);
        $label = $entry['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : ucfirst($mode);
    }

    public function customReferenceIdMaxLength(): int
    {
        return max(1, (int) config('translation_speakers.custom_reference_id.max_length', 128));
    }

    public function customReferenceIdPattern(): string
    {
        $pattern = config('translation_speakers.custom_reference_id.pattern');

        if (! is_string($pattern) || $pattern === '') {
            return '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/';
        }

        return $pattern;
    }

    public function isValidCustomReferenceId(string $referenceId): bool
    {
        $length = strlen($referenceId);

        if ($length < 1 || $length > $this->customReferenceIdMaxLength()) {
            return false;
        }

        return preg_match($this->customReferenceIdPattern(), $referenceId) === 1;
    }

    /**
     * Normalize session-stored speaker selection; invalid custom mode falls back to system.
     *
     * @return array{mode: string, custom_reference_id: string|null}
     */
    public function normalizeSelection(?string $mode, ?string $customReferenceId): array
    {
        $custom = is_string($customReferenceId) ? trim($customReferenceId) : null;
        if ($custom === '') {
            $custom = null;
        }

        $normalizedMode = is_string($mode) && $this->isSupportedMode($mode)
            ? $mode
            : $this->defaultMode();

        if (
            $normalizedMode === self::MODE_CUSTOM
            && ($custom === null || ! $this->isValidCustomReferenceId($custom))
        ) {
            return [
                'mode' => self::MODE_SYSTEM,
                'custom_reference_id' => $custom,
            ];
        }

        return [
            'mode' => $normalizedMode,
            'custom_reference_id' => $custom,
        ];
    }

    /**
     * Resolve the visitor-selected default reference (no language override).
     *
     * @throws InvalidArgumentException
     */
    public function resolveVisitorDefault(string $mode, ?string $customReferenceId): string
    {
        $selection = $this->normalizeSelection($mode, $customReferenceId);

        if ($selection['mode'] === self::MODE_CUSTOM) {
            $custom = $selection['custom_reference_id'];

            if ($custom === null || ! $this->isValidCustomReferenceId($custom)) {
                throw new InvalidArgumentException('A valid custom speaker reference ID is required.');
            }

            return $custom;
        }

        return $this->systemReferenceId();
    }

    /**
     * Resolve the effective Fish reference for a turn.
     *
     * Precedence: language override → visitor default → global system default.
     *
     * @throws InvalidArgumentException
     */
    public function resolveEffectiveReferenceId(
        string $targetLanguage,
        string $mode,
        ?string $customReferenceId = null,
        ?TranslationLanguageCatalog $languages = null,
    ): string {
        $languages ??= app(TranslationLanguageCatalog::class);
        $language = $languages->normalize($targetLanguage);
        $override = $languages->fishReferenceOverride($language);

        if ($override !== null) {
            return $override;
        }

        return $this->resolveVisitorDefault($mode, $customReferenceId);
    }

    /**
     * Global system Fish Audio reference ID (AI_PROVIDER_FISH_REFERENCE_ID).
     *
     * @throws InvalidArgumentException
     */
    public function systemReferenceId(): string
    {
        $fallback = config('services.ai_provider.fish_reference_id');

        if (! is_string($fallback) || $fallback === '') {
            throw new InvalidArgumentException('AIProvider Fish Audio reference_id is not configured.');
        }

        return $fallback;
    }

    /**
     * @return list<mixed>
     */
    public function modeValidationRules(): array
    {
        return ['required', 'string', Rule::in($this->modes())];
    }

    /**
     * Local validation only: required when mode is custom, format, bounded length.
     *
     * @param  string  $modeField  Sibling field name for required_if (Form Request snake_case or Livewire camelCase)
     * @return list<mixed>
     */
    public function customReferenceIdValidationRules(string $modeField = 'speaker_mode'): array
    {
        return [
            'nullable',
            'required_if:'.$modeField.','.self::MODE_CUSTOM,
            'string',
            'max:'.$this->customReferenceIdMaxLength(),
            'regex:'.$this->customReferenceIdPattern(),
        ];
    }
}
