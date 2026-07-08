<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant scoping ditangani di controller (tenant_id diambil dari user login,
        // bukan dari input, supaya tidak bisa dipalsukan lewat request body).
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:percentage,fixed,bogo'],

            // required_if di bawah ini sesuai aturan: target_type & value hanya untuk
            // percentage/fixed. Untuk bogo, keduanya harus null (dicek lagi di withValidator).
            'target_type' => ['nullable', 'in:product,category,cart'],
            'value' => ['nullable', 'numeric', 'min:0'],

            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],

            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],

            // Untuk type percentage/fixed dengan target_type product/category
            'targets' => ['array'],
            'targets.*.target_type' => ['required_with:targets', 'in:product,category'],
            'targets.*.target_id' => ['required_with:targets', 'integer'],

            // Untuk type bogo
            'conditions' => ['array'],
            'conditions.*.product_id' => ['required_with:conditions', 'integer', 'exists:products,id'],
            'conditions.*.min_quantity' => ['required_with:conditions', 'integer', 'min:1'],

            'rewards' => ['array'],
            'rewards.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'rewards.*.quantity' => ['required_with:rewards', 'integer', 'min:1'],
            'rewards.*.discount_percent' => ['required_with:rewards', 'numeric', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');
            $targetType = $this->input('target_type');

            if (in_array($type, ['percentage', 'fixed'], true)) {
                if (! $targetType) {
                    $validator->errors()->add('target_type', 'target_type wajib diisi untuk type percentage/fixed.');
                }
                if ($this->input('value') === null) {
                    $validator->errors()->add('value', 'value wajib diisi untuk type percentage/fixed.');
                }
                if ($targetType === 'cart' && filled($this->input('targets'))) {
                    $validator->errors()->add('targets', 'targets tidak dipakai saat target_type = cart.');
                }
                if (in_array($targetType, ['product', 'category'], true) && empty($this->input('targets'))) {
                    $validator->errors()->add('targets', 'targets wajib diisi minimal 1 untuk target_type product/category.');
                }
                if (filled($this->input('conditions')) || filled($this->input('rewards'))) {
                    $validator->errors()->add('type', 'conditions/rewards hanya untuk type bogo.');
                }
            }

            if ($type === 'bogo') {
                if ($targetType !== null) {
                    $validator->errors()->add('target_type', 'target_type harus kosong untuk type bogo.');
                }
                if ($this->input('value') !== null) {
                    $validator->errors()->add('value', 'value harus kosong untuk type bogo (pakai discount_percent di rewards).');
                }
                if (empty($this->input('conditions'))) {
                    $validator->errors()->add('conditions', 'conditions wajib diisi minimal 1 untuk type bogo.');
                }
                if (empty($this->input('rewards'))) {
                    $validator->errors()->add('rewards', 'rewards wajib diisi minimal 1 untuk type bogo.');
                }
            }
        });
    }
}
