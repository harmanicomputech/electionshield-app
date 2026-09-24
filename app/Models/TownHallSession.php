<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TownHallSession extends Model
{
    /** A session with no end time is treated as two hours long. */
    public const DEFAULT_HOURS = 2;

    protected $fillable = ['slug', 'title', 'description', 'host', 'starts_at', 'ends_at', 'stream_url', 'recording_url', 'questions_open', 'published', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'questions_open' => 'boolean', 'published' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TownHallQuestion::class);
    }

    public function endsAt(): Carbon
    {
        return $this->ends_at ?? $this->starts_at->copy()->addHours(self::DEFAULT_HOURS);
    }

    /**
     * upcoming, live or ended.
     */
    public function phase(): string
    {
        return match (true) {
            now()->lt($this->starts_at) => 'upcoming',
            now()->lt($this->endsAt()) => 'live',
            default => 'ended',
        };
    }

    public function acceptsQuestions(): bool
    {
        return $this->published && $this->questions_open && $this->phase() !== 'ended';
    }

    /**
     * An embeddable player for a YouTube or Facebook link, or null.
     *
     * @return array{provider: string, src: string, thumbnail: ?string}|null
     */
    public static function embed(?string $url): ?array
    {
        if (blank($url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|live/|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $match)) {
            return [
                'provider' => 'youtube',
                'src' => 'https://www.youtube-nocookie.com/embed/'.$match[1].'?autoplay=1&rel=0',
                'thumbnail' => 'https://i.ytimg.com/vi/'.$match[1].'/hqdefault.jpg',
            ];
        }

        if (str_ends_with($host, 'facebook.com') || $host === 'fb.watch') {
            return [
                'provider' => 'facebook',
                'src' => 'https://www.facebook.com/plugins/video.php?href='.rawurlencode($url).'&show_text=false&autoplay=true',
                'thumbnail' => null,
            ];
        }

        return null;
    }
}
