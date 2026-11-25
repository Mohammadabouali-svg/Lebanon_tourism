<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/*
| A photo row only: no file is written. Tests that need the file go through
| the API (and Storage::fake), so ImageUploader really runs.
|
| @extends Factory<ReviewPhoto>
*/
class ReviewPhotoFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'position' => 1,
            'path' => 'reviews/'.Str::uuid().'.webp',
        ];
    }
}
