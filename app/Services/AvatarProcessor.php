<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Turns an uploaded picture into a 256x256 square (centre crop) and stores it on the public disk. Uses PHP's GD. */
class AvatarProcessor
{
    public const SIZE = 256;

    /** Stores the picture and returns its path on the public disk, e.g. "avatars/12-ab3dEf9k.webp". */
    public function store(UploadedFile $file, int $userId): string
    {
        [$binary, $extension] = $this->resize((string) file_get_contents($file->getRealPath()));
        $path = 'avatars/'.$userId.'-'.Str::random(8).'.'.$extension;

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /** @return array{0: string, 1: string} the encoded picture and its file extension */
    private function resize(string $source): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The PHP "gd" extension is needed to resize avatars.');
        }

        $image = @imagecreatefromstring($source);
        if ($image === false) {
            throw new RuntimeException('The file is not a picture PHP can read.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, imagecolorallocatealpha($square, 255, 255, 255, 127));
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($square, null, 85);
            $extension = 'webp';
        } else {
            // No WebP in this GD build: JPEG has no transparency, so put the picture on white.
            $flat = imagecreatetruecolor(self::SIZE, self::SIZE);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $square, 0, 0, 0, 0, self::SIZE, self::SIZE);
            imagejpeg($flat, null, 88);
            $extension = 'jpg';
        }
        $binary = (string) ob_get_clean();

        return [$binary, $extension];
    }
}
