<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(AccessService::class)->canManageShop(
                $this->user(),
                (int) $this->route('shopId')
            );
    }

    public function rules(): array
    {
        $shopId = (int) $this->route('shopId');

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')->where('shop_id', $shopId),
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')
                    ->where('shop_id', $shopId)
                    ->where('is_active', true),
            ],
            'selling_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'unit' => ['sometimes', 'string', 'max:30'],
        ];
    }
}