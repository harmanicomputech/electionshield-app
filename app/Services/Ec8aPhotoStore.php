<?php

namespace App\Services;

use App\Models\Ec8aPhoto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores EC8A photos on the private disk. The file is kept byte for byte
 * (its SHA-256 is recorded for chain of custody); a small JPEG thumbnail is
 * made alongside for lists on slow connections.
 */
class Ec8aPhotoStore
{
    public const MAX_PER_RESULT = 6;

    private const THUMBNAIL_WIDTH = 480;

    /**
     * Store a photo, or return the existing one if this exact file was
     * already uploaded for the reference (a queued upload sent twice).
     */
    public function store(UploadedFile $file, string $reference, string $via, ?string $by, ?string $note = null): Ec8aPhoto
    {
        $sha = hash_file('sha256', $file->getRealPath());

        if ($existing = Ec8aPhoto::query()->where(['result_reference' => $reference, 'sha256' => $sha])->first()) {
            return $existing;
        }

        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $directory = 'ec8a/'.preg_replace('/[^A-Za-z0-9]/', '', $reference);
        $path = $file->storeAs($directory, substr($sha, 0, 32).'.'.$extension, 'local');
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        try {
            return Ec8aPhoto::create([
                'result_reference' => $reference,
                'path' => $path,
                'thumbnail_path' => $this->thumbnail($file->getRealPath(), $directory.'/'.substr($sha, 0, 32).'-thumb.jpg'),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'sha256' => $sha,
                'uploaded_via' => $via,
                'uploaded_by' => $by,
                'note' => $note,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Ec8aPhoto::query()->where(['result_reference' => $reference, 'sha256' => $sha])->firstOrFail();
        }
    }

    public function delete(Ec8aPhoto $photo): void
    {
        Storage::disk('local')->delete(array_filter([$photo->path, $photo->thumbnail_path]));
        $photo->delete();
    }

    /**
     * A JPEG thumbnail, or null when GD can't read the image (the original
     * is then shown in lists too).
     */
    private function thumbnail(string $source, string $path): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));

            if (! $image) {
                return null;
            }

            $thumb = imagescale($image, min(self::THUMBNAIL_WIDTH, imagesx($image)));
            ob_start();
            imagejpeg($thumb, null, 70);
            Storage::disk('local')->put($path, (string) ob_get_clean());

            return $path;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
