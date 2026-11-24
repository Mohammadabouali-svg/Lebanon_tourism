<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['rating', 'comment'])]
class Review extends Model
{
    use HasFactory;

    /** In-memory default, mirroring the database (see User::$attributes). */
    protected $attributes = ['photo_path' => null];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null);
    }
}
