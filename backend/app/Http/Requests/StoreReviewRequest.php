<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/*
| A review: rating, comment and up to three photos (multipart `photos[]`).
|
| Each photo is checked by content (`image` + `mimes`, no SVG: it can carry
| script). That is only the first filter: ImageUploader re-encodes every
| photo, and the database refuses a fourth one (review_photos_position_range).
|
| Size: 5 MB per photo, so three fit in the 20 MB request limit set in
| docker/php/conf.d/app.ini (post_max_size) and nginx (client_max_body_size).
*/
class StoreReviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photos.max' => 'You can add up to 3 photos.',
            'photos.*.image' => 'Photo :position is not an image.',
            'photos.*.mimes' => 'Photo :position must be a JPG, PNG or WebP image.',
            'photos.*.max' => 'Photo :position is larger than 5 MB.',
        ];
    }
}
