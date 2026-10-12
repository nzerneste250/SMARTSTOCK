<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewShopApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->platform_role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}