<?php

namespace App\Http\Controllers;

use App\Support\Files;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Attachments (FR-FIC-01). The route opens to whoever may read or add some category; the
 * database then shows and accepts only the categories the user's permissions open (AC2).
 */
final class FileController
{
    /** Permissions that read, or add to, at least one category (platform.file_category). */
    public const READERS = 'permission:finance.payment.read,expense.attachment.read,student.record.read,staff.profile.read,program.program.read,export.job.create,import.job.create';
    public const WRITERS = 'permission:finance.payment.create,expense.expense.create,student.record.update,staff.profile.update,program.program.manage';

    public function __construct(private Files $files) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|max:51200',
            'category' => ['required', Rule::in(['finance_attachment', 'student_document', 'student_photo', 'staff_document', 'pedagogy_resource'])],
        ]);
        return response()->json(['data' => $this->files->upload($request->file('file'), $data['category'])], 201);
    }

    public function show(string $file)
    {
        return ['data' => $this->files->find($file) ?? abort(404)];
    }

    public function content(string $file)
    {
        $row = $this->files->find($file) ?? abort(404);
        abort_if($row->archived_at !== null, 404);
        return $this->files->download($this->files->key($row->id), $row->original_name, $row->media_type, (int) $row->size_bytes);
    }

    /** AC3: a link valid 15 minutes, for a file the user may read. */
    public function link(string $file)
    {
        $row = $this->files->find($file) ?? abort(404);
        abort_if($row->archived_at !== null, 404);
        return ['data' => ['url' => $this->files->link($row->id), 'expires_in' => Files::LINK_MINUTES * 60]];
    }

    /** Through a signed link, without a session: the signature proves the link was given by a reader. */
    public function linked(string $tenant, string $file)
    {
        $row = DB::selectOne('SELECT storage_key, original_name, media_type, size_bytes FROM school.file_for_link(?::uuid, ?::uuid)', [$tenant, $file]) ?? abort(404);
        return $this->files->download($row->storage_key, $row->original_name, $row->media_type, (int) $row->size_bytes);
    }

    public function archive(Request $request, string $file)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        abort_unless($this->files->find($file), 404);
        DB::select("SELECT set_config('app.reason', ?, true)", [$data['reason']]);
        abort_unless(DB::update('UPDATE school.file_object SET archived_at = now() WHERE id = ? AND archived_at IS NULL', [$file]), 404);
        return ['data' => $this->files->find($file)];
    }
}
