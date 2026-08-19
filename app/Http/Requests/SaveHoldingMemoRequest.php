<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UC-003 (銘柄詳細表示) memo save input validation
 * (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as StoreCsvImportRequest).
 */
class SaveHoldingMemoRequest extends FormRequest
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
            'memo' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'memo.max' => 'メモは2000文字以内で入力してください',
        ];
    }
}
