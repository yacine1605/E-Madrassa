<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/066_teacher_attendance.sql')));
    }

    public function down(): void
    {
        // The entries go; their sessions are unlocked and planned again, as they were before attendance; the guard is 064's.
        $replacements = file_get_contents(database_path('schema/064_replacements.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION school\.guard_class_session\(\).*?END \$\$;/s', $replacements, $guard);
        DB::unprepared(<<<'SQL'
            ALTER TABLE school.class_session DISABLE TRIGGER USER;
            UPDATE school.class_session c SET locked = false,
              status = CASE WHEN c.status = 'delivered' THEN CASE WHEN c.replaced_teacher_id IS NOT NULL THEN 'replaced' ELSE 'planned' END ELSE c.status END
            WHERE EXISTS (SELECT 1 FROM school.teacher_attendance a WHERE a.tenant_id = c.tenant_id AND a.session_id = c.id);
            ALTER TABLE school.class_session ENABLE TRIGGER USER;
            DROP TABLE school.teacher_attendance;
            DROP FUNCTION school.follow_teacher_attendance();
            DROP FUNCTION school.guard_teacher_attendance();
            DELETE FROM platform.audit_domain WHERE object_type = 'teacher_attendance';
            DELETE FROM school.outbox WHERE event = 'attendance.teacher_rejected';
            DELETE FROM school.notification WHERE event = 'attendance.teacher_rejected';
            DELETE FROM school.notification_preference WHERE event = 'attendance.teacher_rejected';
            DELETE FROM platform.notification_event WHERE code = 'attendance.teacher_rejected';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'attendance.entry_days';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'attendance.entry_days';
            SQL);
        DB::unprepared($guard[0]);
    }
};
