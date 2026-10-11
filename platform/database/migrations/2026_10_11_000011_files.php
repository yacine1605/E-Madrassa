<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/011_files.sql')));
    }

    public function down(): void
    {
        // The settings guard as it was before: the definition in 008_referential.sql.
        preg_match('/CREATE OR REPLACE FUNCTION school\.guard_setting\(\).*?END \$\$;/s',
            file_get_contents(database_path('schema/008_referential.sql')), $previous);
        DB::unprepared($previous[0]);
        DB::unprepared(<<<'SQL'
ALTER TABLE school.setting DISABLE TRIGGER guard_writable;
DELETE FROM school.setting WHERE key IN ('files.types', 'files.max_mb');
ALTER TABLE school.setting ENABLE TRIGGER guard_writable;
DELETE FROM platform.setting_definition WHERE key IN ('files.types', 'files.max_mb');
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_check;
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
  CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set'));
ALTER TABLE platform.setting_definition DROP COLUMN allowed_values;
DROP FUNCTION platform.mark_purged(uuid), platform.expired_files(integer), school.file_for_link(uuid, uuid);
DELETE FROM platform.audit_domain WHERE object_type = 'file_object';
DROP TABLE school.file_object;
DROP FUNCTION school.guard_file(), school.storage_use(uuid), school.file_categories(text);
DROP TABLE platform.file_category;
SQL);
    }
};
