<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/026_student_photos.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER retire_photo ON school.student;
            DROP TRIGGER photo ON school.student;
            DROP FUNCTION school.retire_student_photo();
            DROP FUNCTION school.check_student_photo();
            -- Without its thumbnail, a photo is no photo: both go, and the purge deletes their images.
            ALTER TABLE school.student DISABLE TRIGGER USER;
            UPDATE school.student SET photo_file_id = NULL, photo_thumb_file_id = NULL WHERE photo_file_id IS NOT NULL;
            ALTER TABLE school.student ENABLE TRIGGER USER;
            UPDATE school.file_object SET archived_at = coalesce(archived_at, now()), expires_at = now()
              WHERE category = 'student_photo' AND purged_at IS NULL;
            ALTER TABLE school.student DROP CONSTRAINT student_photo_pair, DROP COLUMN photo_thumb_file_id;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'student.photo.enabled';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'student.photo.enabled';
            SQL);
        // The file policies as 019 wrote them, the link function as 011 did, the journal's function as 025 did.
        preg_match_all('/ALTER POLICY (?:reader|writer|updater) ON school\.file_object.*?;/s',
            file_get_contents(database_path('schema/019_signed_context.sql')), $policies);
        DB::unprepared(implode("\n", $policies[0]));
        preg_match('/CREATE FUNCTION school\.file_for_link\(.*?\$\$;/s', file_get_contents(database_path('schema/011_files.sql')), $link);
        DB::unprepared(preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $link[0]));
        preg_match('/CREATE OR REPLACE FUNCTION school\.audit\(\).*?\$\$;/s', file_get_contents(database_path('schema/025_pupil_imports.sql')), $audit);
        DB::unprepared($audit[0]);
    }
};
