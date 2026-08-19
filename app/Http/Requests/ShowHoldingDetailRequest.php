<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UC-003 (銘柄詳細表示) input validation for the `chart_period` query filter
 * (docs/product/use-cases.md).
 *
 * Authorization is handled entirely by the `auth` route middleware (this is
 * a single-user app; same convention as ListHoldingsRequest).
 */
class ShowHoldingDetailRequest extends FormRequest
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
            'chart_period' => ['nullable', 'string', 'in:1y,3y,5y,10y'],
        ];
    }
}
