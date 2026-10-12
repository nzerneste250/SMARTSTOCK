<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
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
        return [
            'event_id' => ['required', 'uuid'],
            'movement_type' => [
                'required',
                'in:stock_received,stock_removed,stock_correction',
            ],
            'quantity' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,3',
            ],
            'direction' => [
                'required',
                'in:increase,decrease',
            ],
            'notes' => [
                'required',
                'string',
                'max:2000',
            ],
        ];
    }
}