<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UC-002 (保有銘柄一覧表示) input validation for the `sector` / `signal_only`
 * query filters (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as StoreCsvImportRequest).
 */
class ListHoldingsRequest extends FormRequest
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
            'sector' => ['nullable', 'string'],
            'signal_only' => ['nullable', 'boolean'],
        ];
    }
}
