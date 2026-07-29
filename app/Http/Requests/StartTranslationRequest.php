<?php

namespace App\Http\Requests;

use App\Services\TranslationLanguageCatalog;
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
}
