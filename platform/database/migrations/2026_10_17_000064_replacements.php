<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/064_replacements.sql')));
    }

    public function down(): void
    {
        // The history, the notices and the replacement's columns go; the guard and the reading are 062's again.
        $sessions = file_get_contents(database_path('schema/062_sessions.sql'));
        preg_match('/CREATE FUNCTION school\.guard_class_session\(\).*?END \$\$;/s', $sessions, $guard);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER record_change ON school.class_session;
            DROP FUNCTION school.record_session_change();
            DROP FUNCTION school.substitutes(uuid);
            DROP TABLE school.session_change;
            DELETE FROM school.outbox WHERE event LIKE 'schedule.session\_%';
            DELETE FROM school.notification WHERE event LIKE 'schedule.session\_%';
            DELETE FROM school.notification_preference WHERE event LIKE 'schedule.session\_%';
            DELETE FROM platform.notification_event WHERE code IN ('schedule.session_cancelled', 'schedule.session_replaced', 'schedule.session_restored');
            ALTER POLICY resource_reader ON school.class_session USING (
              (SELECT school.can_everywhere('schedule.session.read'))
              OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
              OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
              OR school.is_own_staff(teacher_id));
            -- 062 knows no replacement: a session replaced goes back to its teacher planned.
            ALTER TABLE school.class_session DROP CONSTRAINT class_session_replacement, DROP CONSTRAINT class_session_replaced_teacher;
            ALTER TABLE school.class_session DISABLE TRIGGER USER;
            UPDATE school.class_session SET teacher_id = replaced_teacher_id, status = 'planned' WHERE status = 'replaced';
            ALTER TABLE school.class_session ENABLE TRIGGER USER;
            ALTER TABLE school.class_session DROP COLUMN replaced_at, DROP COLUMN replace_reason, DROP COLUMN replaced_teacher_id;
            SQL);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $guard[0]));
    }
};
