<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(AccessService::class)->canProcessSales(
                $this->user(),
                (int) $this->route('shopId')
            );
    }

    public function rules(): array
    {
        return [
            'event_id' => [
                'required',
                'uuid',
            ],

            'payment_method' => [
                'required',
                'in:cash,mobile_money,card',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
            ],

            'items.*.quantity' => [
                'required',
                'regex:/^\d{1,11}(\.\d{1,3})?$/',
                'gt:0',
            ],
        ];
    }
}