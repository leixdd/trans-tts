<?php

namespace App\Http\Requests;

use App\Services\TranslationLanguageCatalog;
use App\Services\TranslationSpeakerCatalog;
use App\Services\TranslationToneCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => self::textRules(),
            'target_language' => self::targetLanguageRules(),
            'translation_tone' => self::translationToneRules(),
            'speaker_mode' => self::speakerModeRules(),
            'custom_reference_id' => self::customReferenceIdRules('speaker_mode'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_reference_id.required_if' => 'A custom speaker reference ID is required.',
            'custom_reference_id.regex' => 'The custom speaker reference ID format is invalid.',
            'custom_reference_id.max' => 'The custom speaker reference ID is too long.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function textRules(): array
    {
        return ['required', 'string', 'max:10000'];
    }

    /**
     * @return list<mixed>
     */
    public static function targetLanguageRules(): array
    {
        /** @var TranslationLanguageCatalog $catalog */
        $catalog = app(TranslationLanguageCatalog::class);

        return ['required', 'string', Rule::in($catalog->codes())];
    }

    /**
     * @return list<mixed>
     */
    public static function translationToneRules(): array
    {
        /** @var TranslationToneCatalog $catalog */
        $catalog = app(TranslationToneCatalog::class);

        return $catalog->validationRules();
    }

    /**
     * @return list<mixed>
     */
    public static function speakerModeRules(): array
    {
        /** @var TranslationSpeakerCatalog $catalog */
        $catalog = app(TranslationSpeakerCatalog::class);

        return $catalog->modeValidationRules();
    }

    /**
     * Local validation only for custom Fish reference IDs.
     *
     * @param  string  $modeField  Sibling field name for required_if (Form Request or Livewire)
     * @return list<mixed>
     */
    public static function customReferenceIdRules(string $modeField = 'speaker_mode'): array
    {
        /** @var TranslationSpeakerCatalog $catalog */
        $catalog = app(TranslationSpeakerCatalog::class);

        return $catalog->customReferenceIdValidationRules($modeField);
    }
}
