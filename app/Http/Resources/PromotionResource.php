<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'target_type' => $this->target_type,
            'value' => $this->value !== null ? (float) $this->value : null,
            'start_date' => $this->start_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(fn ($t) => [
                'id' => $t->id,
                'target_type' => $t->target_type,
                'target_id' => $t->target_id,
            ])),
            'conditions' => $this->whenLoaded('conditions', fn () => $this->conditions->map(fn ($c) => [
                'id' => $c->id,
                'product_id' => $c->product_id,
                'min_quantity' => $c->min_quantity,
            ])),
            'rewards' => $this->whenLoaded('rewards', fn () => $this->rewards->map(fn ($r) => [
                'id' => $r->id,
                'product_id' => $r->product_id,
                'quantity' => $r->quantity,
                'discount_percent' => (float) $r->discount_percent,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
