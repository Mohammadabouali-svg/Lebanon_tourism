<?php

namespace App\Http\Resources;

use App\Models\ItineraryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItineraryItem */
class ItineraryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day' => $this->day,
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}
