<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kind of tour: Cultural, Adventure, Food & Wine, ... */
#[Fillable(['name'])]
class Category extends Model
{
    use HasFactory;

    public function tours(): HasMany
    {
        return $this->hasMany(Tour::class);
    }
}
