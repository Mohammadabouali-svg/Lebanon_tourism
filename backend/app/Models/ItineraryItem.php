<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a tour's day-by-day programme. */
#[Fillable(['day', 'title', 'description'])]
class ItineraryItem extends Model
{
    /** In-memory default, mirroring the database (see User::$attributes). */
    protected $attributes = ['description' => null];

    protected function casts(): array
    {
        return ['day' => 'integer'];
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
}
