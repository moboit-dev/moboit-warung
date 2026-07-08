<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registrasi tenant baru terbuka untuk siapa saja (tidak perlu login).
        return true;
    }

    public function rules(): array
    {
        return [
            // Data tenant (warung/toko)
            'tenant_name' => ['required', 'string', 'max:255'],
            'tenant_address' => ['nullable', 'string', 'max:500'],

            // Data owner (user pertama di tenant ini)
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Wajib isi salah satu: email atau phone, tidak boleh kosong dua-duanya.
            if (empty($this->email) && empty($this->phone)) {
                $validator->errors()->add(
                    'email',
                    'Wajib isi email atau nomor HP (minimal salah satu).'
                );
            }
        });
    }
}
