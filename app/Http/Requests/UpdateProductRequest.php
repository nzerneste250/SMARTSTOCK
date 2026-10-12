<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        $productId = (int) $this->route('productId');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->where('shop_id', $shopId)
                    ->ignore($productId),
            ],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'product_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('product_categories', 'id')
                    ->where('shop_id', $shopId)
                    ->where('is_active', true),
            ],
            'selling_price' => [
                'sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'
            ],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}