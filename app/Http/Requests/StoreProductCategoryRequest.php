<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('product_categories', 'name')
                    ->where('shop_id', $shopId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}