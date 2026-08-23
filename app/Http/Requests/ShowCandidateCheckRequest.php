<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UC-006 (新規投資候補の重複チェック) show input validation
 * (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as SaveHoldingMemoRequest).
 */
class ShowCandidateCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'symbol_code' => ['required', 'string', Rule::exists('holdings', 'symbol_code')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol_code.exists' => '銘柄コードを確認してください',
        ];
    }
}
