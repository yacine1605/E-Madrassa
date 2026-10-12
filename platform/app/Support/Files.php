<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of the school (FR-FIC-01): the register in `school.file_object`, which the database
 * guards (category rights, types, size, quota), and the bytes on the `files` disk under
 * `tenant/{id}/{category}/`. The row is written first: storage follows only an accepted file.
 */
final class Files
{
    public const COLUMNS = 'id, category, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id, expires_at, created_at, created_by, archived_at';
    public const LINK_MINUTES = 15;

    public function __construct(private CurrentSchool $school) {}

    public function upload(UploadedFile $upload, string $category, ?string $ownerType = null, ?string $ownerId = null): object
    {
        $type = FileType::detect($upload->getRealPath(), $upload->getClientOriginalName())
            ?? throw ValidationException::withMessages(['file' => __('errors.file_unrecognized')]);
        $stream = fopen($upload->getRealPath(), 'rb');
        try {
            return $this->register($category, $upload->getClientOriginalName(), $type, $upload->getSize(), hash_file('sha256', $upload->getRealPath()), $stream,
                $ownerType, $ownerId, null);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    /** A document the platform made itself (an export, a PDF, a pupil's photo made again): its type is known. */
    public function store(string $contents, string $category, string $name, string $type, ?\DateTimeInterface $expiresAt = null,
        ?string $ownerType = null, ?string $ownerId = null): object
    {
        return $this->register($category, $name, $type, strlen($contents), hash('sha256', $contents), $contents, $ownerType, $ownerId, $expiresAt);
    }

    public function find(string $id): ?object
    {
        if (!Str::isUuid($id)) return null;
        return DB::selectOne('SELECT '.self::COLUMNS.' FROM school.file_object WHERE id = ? AND purged_at IS NULL', [$id]);
    }

    /** The file itself, as an attachment the browser never interprets. */
    public function download(string $key, string $name, string $media, int $size): StreamedResponse
    {
        return response()->streamDownload(function () use ($key) {
            $stream = Storage::disk('files')->readStream($key);
            fpassthru($stream);
            fclose($stream);
        }, $name, ['Content-Type' => $media, 'Content-Length' => (string) $size, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store']);
    }

    /** An image shown in the page (a pupil's photo), never kept by the browser or a proxy. */
    public function inline(string $key, string $media, int $size): StreamedResponse
    {
        return response()->stream(function () use ($key) {
            $stream = Storage::disk('files')->readStream($key);
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => $media, 'Content-Length' => (string) $size, 'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function key(string $id): string
    {
        return DB::selectOne('SELECT storage_key FROM school.file_object WHERE id = ?', [$id])->storage_key;
    }

    /** FR-FIC-01: a temporary signed link (15 minutes at most) that opens the file without a session. */
    public function link(string $id): string
    {
        return URL::temporarySignedRoute('files.link', now()->addMinutes(self::LINK_MINUTES), ['tenant' => $this->school->id(), 'file' => $id]);
    }

    /** @param resource|string $contents */
    private function register(string $category, string $name, string $type, int $size, string $sha256, $contents,
        ?string $ownerType, ?string $ownerId, ?\DateTimeInterface $expiresAt): object
    {
        $key = 'tenant/'.$this->school->id().'/'.$category.'/'.Str::uuid();
        $row = DB::selectOne('INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256,
            owner_type, owner_id, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING '.self::COLUMNS,
            [$this->school->id(), $category, $key, FileType::cleanName($name), $type, FileType::MEDIA[$type], $size, $sha256, $ownerType, $ownerId,
             $expiresAt?->format(DATE_ATOM)]);
        // Written to storage inside the request's transaction: a failure here undoes the register's row,
        // and a refusal after it removes the bytes the row named.
        Storage::disk('files')->put($key, $contents);
        DB::afterRollBack(fn () => rescue(fn () => Storage::disk('files')->delete($key)));
        return $row;
    }
}
