<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'photo_url' => $this->photo_url,
            // Public pages show "Maya K." rather than a full name.
            'author_name' => $this->whenLoaded('user', fn () => $this->shortName($this->user->name)),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function shortName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [$fullName];
        $first = array_shift($parts);
        $lastInitial = $parts === [] ? '' : ' '.Str::upper(mb_substr((string) end($parts), 0, 1)).'.';

        return $first.$lastInitial;
    }
}
