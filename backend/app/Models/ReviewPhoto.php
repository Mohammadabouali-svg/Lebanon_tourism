<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/*
| One photo of a review (at most three per review, see the migration).
|
| Nothing here is fillable: the review, the position and the file path are
| all decided by ReviewService, never by the request (invariant 9).
*/
class ReviewPhoto extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->path));
    }
}
