<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Secure image handling: the Form Request validates mime/size/dimensions, then every image is DECODED and
 * RE-ENCODED with GD (strips EXIF/GPS metadata and any polyglot payload), resized to a sane maximum and
 * stored on the local public disk under a random name.
 */
class ImageUploadService
{
    public const RULES = ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=300,min_height=200,max_width=8000,max_height=8000'];

    public function store(UploadedFile $file, string $directory, int $maxWidth = 1920, int $quality = 80): string
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw DomainException::make('Please upload a valid JPG, PNG or WebP image.', 'invalid_image');
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $src) {
            throw DomainException::make('This image could not be processed. Please try another file.', 'invalid_image');
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        if ($w > $maxWidth) {
            $nh = (int) round($h * $maxWidth / $w);
            $dst = imagecreatetruecolor($maxWidth, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, $quality);
        $binary = ob_get_clean();
        imagedestroy($src);

        $path = trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::random(32).'.jpg';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'seed/') && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }
}
