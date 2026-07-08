<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'login' bisa berisi email ATAU phone, ditentukan saat proses di controller.
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
