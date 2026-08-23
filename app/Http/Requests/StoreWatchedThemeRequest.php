<?php

namespace App\Http\Requests;

use App\Models\WatchedTheme;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UC-008 (注目テーマ・セクターの登録・更新) create input validation
 * (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as SaveHoldingMemoRequest).
 */
class StoreWatchedThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /**
     * Rejects a duplicate `name` as a 422 validation error (Gate 4 confirmed
     * contract), rather than letting the DB's unique constraint surface as
     * an unhandled 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name');

            if (is_string($name) && $name !== '' && WatchedTheme::where('name', $name)->exists()) {
                $validator->errors()->add('name', 'このテーマ・セクター名は既に登録されています');
            }
        });
    }
}
