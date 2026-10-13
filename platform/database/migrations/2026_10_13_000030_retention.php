<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/030_retention.sql')));
    }

    public function down(): void
    {
        $schema = fn (string $file) => file_get_contents(database_path('schema/'.$file));
        $function = function (string $file, string $name) use ($schema): string {
            preg_match('/CREATE (?:OR REPLACE )?FUNCTION '.preg_quote($name, '/').'\(.*?END \$\$;/s', $schema($file), $found);
            return preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $found[0]);
        };
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.retention_due();
            DROP FUNCTION platform.apply_retention(integer);
            DROP FUNCTION platform.retention_candidates(uuid);
            DROP FUNCTION platform.retention_value(uuid, text);
            DROP TRIGGER immutable ON school.audit_log;
            CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.audit_log FOR EACH ROW EXECUTE FUNCTION school.immutable();
            DROP FUNCTION school.guard_audit_log();
            SQL);
        // The rules as they were: 019's guard of a read-only school, 008's check of the school's fields, 021's guard of a pupil.
        DB::unprepared($function('019_signed_context.sql', 'school.guard_writable'));
        DB::unprepared($function('008_referential.sql', 'school.check_custom_values'));
        DB::unprepared($function('021_pupils_and_enrolments.sql', 'school.guard_student'));
        // Anonymised records stay anonymised; their dates go with their columns, the journal keeps them.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.retention_run();
            DROP TRIGGER track_departure ON school.student;
            DROP FUNCTION school.track_departure();
            ALTER TABLE school.guardian DROP COLUMN anonymised_at;
            ALTER TABLE school.student DROP COLUMN anonymised_at, DROP COLUMN left_on;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key LIKE 'retention.%';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key LIKE 'retention.%';
            SQL);
    }
};
