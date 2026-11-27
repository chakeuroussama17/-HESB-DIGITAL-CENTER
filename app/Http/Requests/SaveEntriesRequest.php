<?php

namespace App\Http\Requests;

use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape + format validation for a month of grid cells. Business rules (line belongs
 * to the company, lock, future weeks) are enforced by EntryService. Authorisation
 * is done in the controller against the resolved company, never request input.
 */
class SaveEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $amount = function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && ! Money::isValid($value)) {
                $fail('Each amount must be a number with up to 2 decimals (e.g. 1,234.56 or (1,234.56)).');
            }
        };

        return [
            'cells' => ['required', 'array', 'max:200'],
            'cells.*' => ['array', 'max:6'],
            'cells.*.*' => ['array'],
            'cells.*.*.actual' => ['nullable', 'max:24', $amount],
            'cells.*.*.forecast' => ['nullable', 'max:24', $amount],
        ];
    }

    public function attributes(): array
    {
        return ['cells.*.*.actual' => 'actual', 'cells.*.*.forecast' => 'forecast'];
    }
}
