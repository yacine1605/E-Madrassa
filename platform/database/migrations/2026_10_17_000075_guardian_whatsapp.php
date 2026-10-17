<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/075_guardian_whatsapp.sql')));
    }

    public function down(): void
    {
        // Email only again, as 074's: the WhatsApp messages and their setting go.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.fail_guardian_message(uuid, text);
            DELETE FROM school.guardian_message WHERE channel = 'whatsapp';
            CREATE OR REPLACE FUNCTION school.tell_guardians() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE majority integer;
            BEGIN
              IF NOT coalesce((school.setting_value('attendance.notify_guardians_email') #>> '{}')::boolean, false) THEN RETURN NULL; END IF;
              majority := (school.setting_value('student.majority_age') #>> '{}')::integer;
              INSERT INTO school.guardian_message (tenant_id, alert_id, guardian_id, channel)
              SELECT NEW.tenant_id, NEW.id, g.id, 'email' FROM school.student s
              JOIN school.student_guardian l ON l.tenant_id = s.tenant_id AND l.student_id = s.id AND l.is_primary_contact AND l.archived_at IS NULL
              JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id AND g.archived_at IS NULL AND g.email IS NOT NULL
              WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.student_id AND s.birth_date > NEW.to_day - make_interval(years => majority);
              RETURN NULL;
            END $$;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'attendance.notify_guardians_whatsapp';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'attendance.notify_guardians_whatsapp';
            SQL);
    }
};
