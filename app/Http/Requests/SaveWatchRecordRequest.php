<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UC-006 (新規投資候補の重複チェック) watch record save input validation
 * (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as SaveHoldingMemoRequest).
 */
class SaveWatchRecordRequest extends FormRequest
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
            'watch_status' => ['nullable', Rule::in(['様子見', '買い時', '次回購入候補', 'リバランス対象'])],
            'watch_memo' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol_code.exists' => '銘柄コードを確認してください',
            'watch_memo.max' => 'メモは2000文字以内で入力してください',
        ];
    }

    /**
     * Rejects a POST with neither watch_status nor watch_memo as a 422
     * (Gate 4 confirmed contract) — there would be nothing meaningful to
     * append to the history log otherwise.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $watchStatus = $this->input('watch_status');
            $watchMemo = $this->input('watch_memo');

            $hasWatchStatus = is_string($watchStatus) && $watchStatus !== '';
            $hasWatchMemo = is_string($watchMemo) && $watchMemo !== '';

            if (! $hasWatchStatus && ! $hasWatchMemo) {
                $validator->errors()->add('watch_status', 'watch_statusまたはwatch_memoのいずれかを指定してください');
            }
        });
    }
}
