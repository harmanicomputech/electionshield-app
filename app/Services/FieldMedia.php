<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Photos and videos sent with an agent's report (or added later). Photos of
 * a result go to the EC8A photo store, where they are checked against the
 * figures; everything else is kept as an Attachment. Files are stored byte
 * for byte on the private disk with their SHA-256, and the same file sent
 * twice (a queued upload retried) is stored once.
 */
class FieldMedia
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public const VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp', 'video/x-matroska'];

    public function __construct(private Ec8aPhotoStore $photos) {}

    /**
     * Validation rules for a `media[]` upload field.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $maxKb = (int) config('election.media.max_video_mb') * 1024;
        $types = implode(',', array_merge(self::IMAGE_TYPES, self::VIDEO_TYPES));

        return [
            'media' => ['nullable', 'array', 'max:'.config('election.media.max_files')],
            'media.*' => ['file', "mimetypes:{$types}", "max:{$maxKb}"],
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{photos: int, videos: int}
     */
    public function attach(array $files, string $reference, bool $isResult, User $user): array
    {
        $counts = ['photos' => 0, 'videos' => 0];

        foreach ($files as $file) {
            $isImage = str_starts_with((string) $file->getMimeType(), 'image/');

            if ($isResult && $isImage && $file->getMimeType() !== 'image/heic' && $file->getMimeType() !== 'image/heif') {
                $this->photos->store($file, $reference, 'agent', $user->name);
            } else {
                $this->store($file, $reference, $isImage ? Attachment::IMAGE : Attachment::VIDEO, $user);
            }

            $counts[$isImage ? 'photos' : 'videos']++;
        }

        return $counts;
    }

    public function store(UploadedFile $file, string $reference, string $kind, ?User $user): Attachment
    {
        $sha = hash_file('sha256', $file->getRealPath());

        if ($existing = Attachment::query()->where(['reference' => $reference, 'sha256' => $sha])->first()) {
            return $existing;
        }

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs('media/'.preg_replace('/[^A-Za-z0-9]/', '', $reference), substr($sha, 0, 32).'.'.$extension, 'local');

        try {
            return Attachment::create([
                'reference' => $reference,
                'kind' => $kind,
                'path' => $path,
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'sha256' => $sha,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'uploaded_by' => $user?->name,
                'user_id' => $user?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Attachment::query()->where(['reference' => $reference, 'sha256' => $sha])->firstOrFail();
        }
    }

    public function delete(Attachment $attachment): void
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * The largest upload PHP on this server accepts, in MB (the smaller of
     * upload_max_filesize and post_max_size), for the System page.
     */
    public static function serverLimitMb(): int
    {
        $bytes = fn (string $value) => (int) $value * match (strtolower(substr(trim($value), -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
        };

        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits ? (int) floor(min($limits) / 1048576) : 0;
    }
}
