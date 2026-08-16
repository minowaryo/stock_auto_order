<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

/**
 * UC-001 (CSV取込) input validation.
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; docs/architecture/data-model.md notes no Policy-level
 * role branching is needed), so authorize() simply allows any authenticated
 * request that reaches this FormRequest.
 */
class StoreCsvImportRequest extends FormRequest
{
    private const MAX_FILE_SIZE_KB = 5120; // 5MB (UC-001業務ルール)

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
            'jp_stock_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
            'us_stock_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
            'mutual_fund_file' => ['nullable', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jp_stock_file.required' => '国内株式・米国株式のCSVは両方アップロードしてください',
            'us_stock_file.required' => '国内株式・米国株式のCSVは両方アップロードしてください',
            'jp_stock_file.extensions' => 'CSVファイルのみアップロードできます',
            'us_stock_file.extensions' => 'CSVファイルのみアップロードできます',
            'mutual_fund_file.extensions' => 'CSVファイルのみアップロードできます',
        ];
    }

    /**
     * A file exceeding the 5MB limit is a distinct error case from other
     * validation failures (UC-001エラーケース: 413, not 422), so it is
     * special-cased here rather than relying on the default 422 JSON
     * response every other validation failure gets.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        foreach (['jp_stock_file', 'us_stock_file', 'mutual_fund_file'] as $field) {
            $file = $this->file($field);

            if (
                $file instanceof UploadedFile
                && $file->isValid()
                && $file->getSize() > self::MAX_FILE_SIZE_KB * 1024
            ) {
                throw new HttpResponseException(response()->json([
                    'message' => 'ファイルサイズが上限（5MB）を超えています',
                ], 413));
            }
        }

        parent::failedValidation($validator);
    }
}
