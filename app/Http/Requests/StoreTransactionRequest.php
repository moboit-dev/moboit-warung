<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body: { payment_method, items: [{product_id, unit, quantity, price}] }
 *
 * `price` di sini masih dipercaya sebagai harga NORMAL per unit (dipakai
 * PromotionService sebagai input evaluasi), BUKAN harga setelah diskon —
 * diskon selalu dihitung ulang di server, tidak pernah dipercaya dari client.
 */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => 'required|string|in:cash,qris,transfer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.unit' => 'nullable|string|in:besar,kecil',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ];
    }
}
