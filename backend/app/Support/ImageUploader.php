<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| Stores user-uploaded images safely.
|
| Validation (`image`, `mimes`, `max`) already rejects non-images, but we go
| one step further and *re-encode* every upload:
|
|   1. The file is decoded into raw pixels by GD. Anything that is not a
|      real image fails here.
|   2. A brand-new WebP/JPEG file is written from those pixels.
|
| Effects:
|   - Hidden payloads (e.g. PHP code appended to a valid JPEG, a "polyglot"
|     file) are discarded, because only the pixels survive.
|   - Metadata is stripped. Phone photos contain EXIF data such as the GPS
|     position where they were taken. Publishing that would leak a
|     traveler's home address.
|   - Oversized photos are scaled down, saving storage and bandwidth.
|   - The file name is random and the extension is chosen by us, never taken
|     from the uploaded file name.
*/
final class ImageUploader
{
    public function __construct(
        private readonly int $maxWidth = 1600,
        private readonly string $disk = 'public',
    ) {}

    /**
     * @param  string  $field  the form field name, used in the error message
     * @return string the stored path, relative to the disk root
     *
     * @throws ValidationException when the file cannot be decoded as an image
     */
    public function store(UploadedFile $file, string $directory, string $field = 'image'): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            // Reaching this line means the file fooled the `image` validation
            // rule. It is still refused, as a normal 422 validation error.
            throw ValidationException::withMessages([$field => 'The file is not a valid image.']);
        }

        if (imagesx($source) > $this->maxWidth) {
            $source = imagescale($source, $this->maxWidth) ?: $source;
        }

        $useWebp = function_exists('imagewebp');
        $extension = $useWebp ? 'webp' : 'jpg';

        // GD writes to the output buffer when given no file name; we capture it.
        ob_start();
        $useWebp ? imagewebp($source, null, 82) : imagejpeg($source, null, 85);
        $bytes = (string) ob_get_clean();

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        Storage::disk($this->disk)->put($path, $bytes);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk($this->disk)->delete($path);
        }
    }
}
