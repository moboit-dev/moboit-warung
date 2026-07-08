<?php

namespace App\Http\Requests;

class UpdatePromotionRequest extends StorePromotionRequest
{
    // Aturan validasi sama persis dengan pembuatan promo baru (full replace semantics:
    // saat update, seluruh targets/conditions/rewards lama diganti oleh yang baru dikirim).
}
