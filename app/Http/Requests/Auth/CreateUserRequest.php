<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya owner/admin yang boleh membuat user baru dalam tenant-nya.
        // Dicek di sini (bukan cuma di controller) supaya request langsung
        // ditolak 403 sebelum masuk ke logic apapun.
        return $this->user()?->canManageUsers() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Owner tidak dibuat lewat endpoint ini (owner dibuat saat register tenant).
            // Admin hanya boleh dibuat oleh owner, bukan sesama admin.
            'role' => ['required', Rule::in(['admin', 'kasir'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->email) && empty($this->phone)) {
                $validator->errors()->add(
                    'email',
                    'Wajib isi email atau nomor HP (minimal salah satu).'
                );
            }

            // Hanya owner yang boleh membuat user dengan role admin.
            if ($this->role === 'admin' && ! $this->user()->isOwner()) {
                $validator->errors()->add(
                    'role',
                    'Hanya owner yang boleh membuat user dengan role admin.'
                );
            }
        });
    }
}
