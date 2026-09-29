<?php

namespace App\Jobs;

use App\Models\Contact;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Normalizes an uploaded contact photo (resize + re-encode to JPEG, which
 * also strips EXIF metadata) and generates a square thumbnail.
 */
class ProcessContactPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Contact $contact,
        public string $path,
    ) {}

    public function handle(): void
    {
        $contact = $this->contact->fresh();

        // The photo was replaced or removed before this job ran.
        if ($contact === null || $contact->photo_path !== $this->path) {
            return;
        }

        $disk = Contact::photoDisk();
        $source = @imagecreatefromstring((string) $disk->get($this->path));

        if (! $source instanceof GdImage) {
            Log::warning('Unreadable contact photo', ['contact_id' => $contact->id, 'path' => $this->path]);

            return;
        }

        $base = preg_replace('/\.[^.\/]+$/', '', $this->path);
        $photoPath = $base.'.jpg';
        $thumbPath = $base.'_thumb.jpg';

        $disk->put($photoPath, $this->encode($this->fit($source, config('orbit.photos.max_dimension'))));
        $disk->put($thumbPath, $this->encode($this->square($source, config('orbit.photos.thumb_dimension'))));

        if ($photoPath !== $this->path) {
            $disk->delete($this->path);
        }

        $contact->forceFill(['photo_path' => $photoPath, 'photo_thumb_path' => $thumbPath])->saveQuietly();
    }

    /**
     * Scale down (never up) so the longest side is at most $max pixels.
     */
    private function fit(GdImage $image, int $max): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $max / max($w, $h));

        return $this->resample($image, 0, 0, $w, $h, (int) round($w * $scale), (int) round($h * $scale));
    }

    /**
     * Center-crop to a square and scale to $size pixels.
     */
    private function square(GdImage $image, int $size): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $size = min($size, $side);

        return $this->resample($image, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $side, $side, $size, $size);
    }

    private function resample(GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): GdImage
    {
        $dst = imagecreatetruecolor(max(1, $dw), max(1, $dh));
        // White background for transparent PNG/WebP/GIF sources.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 85);
        $data = ob_get_clean();

        if ($data === false || $data === '') {
            throw new RuntimeException('Failed to encode contact photo.');
        }

        return $data;
    }
}
