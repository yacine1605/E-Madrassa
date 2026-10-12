<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A pupil's photo (FR-ELV-01): optional; JPEG, PNG or WebP of 2 MB at most as sent. Made again
 * here: turned upright, reduced to 1 024 pixels and a square thumbnail of 128, written as JPEG
 * without anything of the sent file but its pixels (no EXIF, no location). The database keeps
 * which two files are the pupil's and retires the previous ones (SQL 026); their bytes go once
 * the change is committed.
 */
final class StudentPhotos
{
    public const MAX_KB = 2048;
    public const FULL = 1024;
    public const THUMB = 128;
    /** Pixels decoded at most: 64 MB in memory, twice that while turned or cropped; a phone photo of 2 MB has some 12 million. */
    public const MAX_PIXELS = 16_000_000;

    public function __construct(private Files $files) {}

    /** The school may turn photos off: they are hidden, not deleted. */
    public function enabled(): bool
    {
        return json_decode(DB::selectOne("SELECT school.setting_value('student.photo.enabled') AS v")->v) === true;
    }

    /** @return array{0: object, 1: object} the photo and its thumbnail, registered as the pupil's */
    public function store(UploadedFile $upload, string $student, bool $square): array
    {
        [$photo, $thumb] = self::make($upload, $square);
        return [$this->files->store($photo, 'student_photo', 'photo.jpg', 'jpeg', ownerType: 'student', ownerId: $student),
            $this->files->store($thumb, 'student_photo', 'vignette.jpg', 'jpeg', ownerType: 'student', ownerId: $student)];
    }

    /**
     * The images a pupil no longer shows, already retired by the database: their bytes are deleted
     * once the change is committed, never before. The daily purge finishes what fails here.
     *
     * @param list<?string> $ids
     */
    public function discardAfterCommit(array $ids): void
    {
        $files = DB::select('SELECT id, storage_key FROM school.file_object WHERE id = ANY (?::uuid[])', ['{'.implode(',', array_filter($ids)).'}']);
        DB::afterCommit(function () use ($files) {
            foreach ($files as $file) {
                rescue(function () use ($file) {
                    Storage::disk('files')->delete($file->storage_key);
                    DB::select('SELECT platform.mark_purged(?)', [$file->id]);
                });
            }
        });
    }

    /**
     * @param bool $square cropped to its centred square, as the screen proposes
     * @return array{0: string, 1: string} the photo and its thumbnail, as JPEG
     */
    public static function make(UploadedFile $upload, bool $square): array
    {
        if ($upload->getSize() > self::MAX_KB * 1024) throw self::refused('photo_too_large');
        $type = FileType::detect($upload->getRealPath(), $upload->getClientOriginalName());
        if (!in_array($type, ['jpeg', 'png', 'webp'], true)) throw self::refused('photo_invalid');
        $bytes = (string) file_get_contents($upload->getRealPath());
        // The size the file declares, read before decoding anything: a small file may announce
        // an image far too large to decode.
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) throw self::refused('photo_invalid');
        if ($size[0] * $size[1] > self::MAX_PIXELS) throw self::refused('photo_dimensions');
        $image = @imagecreatefromstring($bytes);
        if ($image === false) throw self::refused('photo_invalid');
        if ($type === 'jpeg') $image = self::upright($image, self::orientation($bytes));
        [$width, $height] = [imagesx($image), imagesy($image)];
        $side = min($width, $height);
        [$x, $y] = [intdiv($width - $side, 2), intdiv($height - $side, 2)];
        if ($square) [$image, $width, $height, $x, $y] = [self::copy($image, $x, $y, $side, $side, $side, $side), $side, $side, 0, 0];
        $scale = min(1, self::FULL / max($width, $height));
        $photo = self::copy($image, 0, 0, $width, $height, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        $thumb = self::copy($image, $x, $y, $side, $side, min(self::THUMB, $side), min(self::THUMB, $side));
        return [self::jpeg($photo), self::jpeg($thumb)];
    }

    private static function refused(string $code): ValidationException
    {
        return ValidationException::withMessages(['photo' => __('errors.'.$code)]);
    }

    /** A region of the image on a new white canvas: transparency becomes white, nothing else is carried over. */
    private static function copy(GdImage $source, int $x, int $y, int $width, int $height, int $toWidth, int $toHeight): GdImage
    {
        $target = imagecreatetruecolor($toWidth, $toHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $x, $y, $toWidth, $toHeight, $width, $height);
        return $target;
    }

    private static function jpeg(GdImage $image): string
    {
        $stream = fopen('php://memory', 'w+b');
        imagejpeg($image, $stream, 85);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);
        return $bytes;
    }

    /** A camera stores its photo as the sensor saw it and says how to turn it (EXIF orientation, 1 to 8). */
    private static function upright(GdImage $image, int $orientation): GdImage
    {
        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($turned === false) return $image;
        if (in_array($orientation, [2, 5, 7], true)) imageflip($turned, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($turned, IMG_FLIP_VERTICAL);
        return $turned;
    }

    /** The orientation tag of a JPEG's EXIF block, read without any library; 1 when absent or unreadable. */
    private static function orientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        for ($offset = 2; $offset + 4 <= $length && $jpeg[$offset] === "\xFF";) {
            $marker = ord($jpeg[$offset + 1]);
            // Start of the image data, or its end: no EXIF block after this.
            if ($marker === 0xDA || $marker === 0xD9) break;
            $size = unpack('n', $jpeg, $offset + 2)[1];
            if ($size < 2) break;
            if ($marker === 0xE1 && substr($jpeg, $offset + 4, 6) === "Exif\0\0") return self::tiffOrientation(substr($jpeg, $offset + 10, $size - 8));
            $offset += 2 + $size;
        }
        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $short = match (substr($tiff, 0, 2)) { 'II' => 'v', 'MM' => 'n', default => null };
        if ($short === null || strlen($tiff) < 8) return 1;
        $ifd = unpack($short === 'v' ? 'V' : 'N', $tiff, 4)[1];
        if ($ifd + 2 > strlen($tiff)) return 1;
        $entries = unpack($short, $tiff, $ifd)[1];
        for ($i = 0; $i < $entries && $ifd + 14 + $i * 12 <= strlen($tiff); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                $value = unpack($short, $tiff, $entry + 8)[1];
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }
}
