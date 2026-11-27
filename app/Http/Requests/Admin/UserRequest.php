<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
            // Group roles never belong to a company.
            'company_id' => $this->input('role') === Role::CompanyUser->value ? $this->input('company_id') : null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'role' => ['required', Rule::enum(Role::class)],
            'company_id' => [
                Rule::requiredIf($this->input('role') === Role::CompanyUser->value),
                'nullable', 'integer', Rule::exists('companies', 'id'),
            ],
            'is_active' => ['boolean'],
            'password' => [$id ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return ['company_id.required' => 'A company user must belong to exactly one company.'];
    }
}
