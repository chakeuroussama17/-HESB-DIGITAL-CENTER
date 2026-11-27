<?php

namespace App\Http\Requests;

use App\Enums\LineGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked against the resolved company in the controller
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'group' => ['required', Rule::enum(LineGroup::class)],
        ];
    }

    public function messages(): array
    {
        return ['group.required' => 'Choose which total the new row rolls into.'];
    }
}
