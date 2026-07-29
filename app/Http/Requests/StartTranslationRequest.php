<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'text' => self::textRules(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function textRules(): array
    {
        return ['required', 'string', 'max:10000'];
    }
}
