<?php

namespace App\Http\Requests\Preferences;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A visitor choosing the language they read in.
 *
 * Open to anyone: a guest reading a menu at a table has no account, and
 * choosing a language is not a privileged act.
 */
class UpdateLanguageRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', Rule::enum(Locale::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locale.required' => 'Choose a language.',
            'locale.Illuminate\Validation\Rules\Enum' => 'That is not a language this app is available in.',
        ];
    }

    /**
     * The language that was chosen.
     */
    public function locale(): Locale
    {
        return Locale::from($this->string('locale')->toString());
    }
}
