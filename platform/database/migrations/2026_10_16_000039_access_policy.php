<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/039_access_policy.sql')));
    }

    public function down(): void
    {
        // The settings' guard as 011 wrote it, first; the setting goes with the schools' values of it.
        preg_match('/CREATE OR REPLACE FUNCTION school\.guard_setting\(\).*?END \$\$;/s', file_get_contents(database_path('schema/011_files.sql')), $function);
        DB::unprepared($function[0]);
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.access_granted(uuid);
            DROP FUNCTION school.access_open(text, uuid);
            DROP FUNCTION school.enrollment_paid(uuid, text);
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'enrollment.access.require_payment';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'enrollment.access.require_payment';
            ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_allowed_values;
            ALTER TABLE platform.setting_definition ADD CHECK ((value_type = 'code_set') = (allowed_values IS NOT NULL));
            ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
            ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
              CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set', 'code_set'));
            SQL);
    }
};
